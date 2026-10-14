<?php
/**
 * Course copy endpoint for imathas-nx. (Fork-only file.)
 *
 * "Copy this course for next year": nx makes the new, empty course (as admin/actions.php
 * "addcourse" writes it) and then asks the engine, server to server, to fill it from the old
 * one — on the same HMAC trust as lti/nxhandoff.php and lti/nximport.php (NX_HANDOFF_SECRET).
 *
 *   POST token=<base64url(json)>.<base64url(hmac-sha256)>
 *   json: { "action": "copy", "userId": N, "sourceCourseId": N, "destCourseId": N, "exp": T,
 *           "options": { "offline": bool, "rubrics": bool, "outcomes": bool } }
 *   200 { "assessments": { "<old id>": <new id>, ... }, "blocks": { "<old>": <new>, ... },
 *         "items": n }
 *
 * The copy is admin/actions.php "addcourse" with a template course (usetemplate), the same
 * steps in the same order: the gradebook scheme and categories, outcomes, every item through
 * includes/copyiteminc.php (copyallsub + doaftercopy: assessments with their questions,
 * folders, text, links — withdrawn questions left out, replaced questions swapped for their
 * replacements), then offline grade items and rubrics. One transaction: a copy that fails
 * part-way leaves the new course empty, never half full.
 *
 * The teacher must teach BOTH courses (imas_teachers), the courses must differ, and the new
 * course must be empty — so a repeated request cannot copy twice into it.
 */
require_once __DIR__ . '/../init_without_validate.php';

header('Content-Type: application/json');
// A JSON endpoint: a warning printed ahead of the body would make the caller misread it.
ini_set('display_errors', '0');
ini_set('max_execution_time', '900');
function nx_fail($code, $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    nx_fail(405, 'POST only.');
}
$secret = getenv('NX_HANDOFF_SECRET');
if (empty($secret)) {
    nx_fail(500, 'Not configured on this server (NX_HANDOFF_SECRET).');
}
$token = (string) ($_POST['token'] ?? '');
$dot = strpos($token, '.');
if ($dot === false || $dot === 0) {
    nx_fail(400, 'Bad token.');
}
$body = substr($token, 0, $dot);
$mac = substr($token, $dot + 1);
$expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $secret, true)), '+/', '-_'), '=');
if (!hash_equals($expected, $mac)) {
    nx_fail(401, 'Signature does not verify.');
}
$payload = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
if (!is_array($payload) || ($payload['exp'] ?? 0) < time()) {
    nx_fail(401, 'Expired or malformed.');
}
if (($payload['action'] ?? '') !== 'copy') {
    nx_fail(400, 'Unknown action.');
}

// The copying teacher, as validate.php would have set them for the stock page.
$stm = $DBH->prepare('SELECT id, rights, groupid FROM imas_users WHERE id=?');
$stm->execute([(int) ($payload['userId'] ?? 0)]);
$u = $stm->fetch(PDO::FETCH_ASSOC);
if ($u === false || (int) $u['rights'] < 20) {
    nx_fail(403, 'Not a teacher account.');
}
$userid = (int) $u['id'];
$myrights = (int) $u['rights'];
$groupid = (int) $u['groupid'];
$GLOBALS['userid'] = $userid;
$GLOBALS['myrights'] = $myrights;
$GLOBALS['groupid'] = $groupid;

$sourcecid = (int) ($payload['sourceCourseId'] ?? 0);
$destcid = (int) ($payload['destCourseId'] ?? 0);
if ($sourcecid <= 0 || $destcid <= 0 || $sourcecid === $destcid) {
    nx_fail(400, 'Two different courses are needed.');
}
$teaches = $DBH->prepare('SELECT 1 FROM imas_teachers WHERE userid=? AND courseid=?');
foreach ([$sourcecid, $destcid] as $c) {
    $teaches->execute([$userid, $c]);
    if ($teaches->fetchColumn() === false) {
        nx_fail(403, 'You do not teach both courses.');
    }
}
$stm = $DBH->prepare('SELECT itemorder, ancestors, outcomes, UIver FROM imas_courses WHERE id=?');
$stm->execute([$sourcecid]);
$src = $stm->fetch(PDO::FETCH_ASSOC);
$stm->execute([$destcid]);
$dst = $stm->fetch(PDO::FETCH_ASSOC);
if ($src === false || $dst === false) {
    nx_fail(404, 'No such course.');
}
$dstItems = unserialize($dst['itemorder']);
$stm = $DBH->prepare('SELECT COUNT(*) FROM imas_items WHERE courseid=?');
$stm->execute([$destcid]);
if ((is_array($dstItems) && count($dstItems) > 0) || (int) $stm->fetchColumn() > 0) {
    nx_fail(409, 'The new course already has items; a copy goes into an empty course only.');
}
$stm = $DBH->prepare('SELECT dates_by_lti FROM imas_courses WHERE id=?');
$stm->execute([$destcid]);
$datesbylti = (int) $stm->fetchColumn();

$opts = is_array($payload['options'] ?? null) ? $payload['options'] : [];

// ---- From here, admin/actions.php "addcourse" with usetemplate, step for step. ----
$cid = $destcid; // copyiteminc.php writes into global $cid
$GLOBALS['cid'] = $cid;
$GLOBALS['datesbylti'] = $datesbylti;
$destUIver = max(2, (int) $dst['UIver']);
if ((int) $src['UIver'] > 1) {
    $destUIver = (int) $src['UIver'];
}

$DBH->beginTransaction();
try {
    $stm = $DBH->prepare('SELECT useweights,orderby,defaultcat,defgbmode,stugbmode FROM imas_gbscheme WHERE courseid=?');
    $stm->execute([$sourcecid]);
    $row = $stm->fetch(PDO::FETCH_NUM);
    if ($row !== false) {
        $up = $DBH->prepare('UPDATE imas_gbscheme SET useweights=?,orderby=?,defaultcat=?,defgbmode=?,stugbmode=? WHERE courseid=?');
        $up->execute([$row[0], $row[1], $row[2], $row[3], $row[4], $destcid]);
    }

    $gbcats = [];
    $stm = $DBH->prepare('SELECT id,name,scale,scaletype,chop,dropn,weight,hidden,calctype FROM imas_gbcats WHERE courseid=?');
    $stm->execute([$sourcecid]);
    $ins = $DBH->prepare('INSERT INTO imas_gbcats (courseid,name,scale,scaletype,chop,dropn,weight,hidden,calctype) VALUES (?,?,?,?,?,?,?,?,?)');
    while ($r = $stm->fetch(PDO::FETCH_ASSOC)) {
        $ins->execute([$destcid, $r['name'], $r['scale'], $r['scaletype'], $r['chop'], $r['dropn'], $r['weight'], $r['hidden'], $r['calctype']]);
        $gbcats[$r['id']] = $DBH->lastInsertId();
    }

    $items = unserialize($src['itemorder']);
    if (!is_array($items)) {
        $items = [];
    }
    $ancestors = $src['ancestors'] == '' ? (string) $sourcecid : $sourcecid . ',' . $src['ancestors'];

    $replacebyarr = [];
    $stm = $DBH->prepare('SELECT imas_questionset.id,imas_questionset.replaceby FROM imas_questionset JOIN
        imas_questions ON imas_questionset.id=imas_questions.questionsetid JOIN
        imas_assessments ON imas_assessments.id=imas_questions.assessmentid WHERE
        imas_assessments.courseid=? AND imas_questionset.replaceby>0');
    $stm->execute([$sourcecid]);
    while ($r = $stm->fetch(PDO::FETCH_NUM)) {
        $replacebyarr[$r[0]] = $r[1];
    }

    $outcomes = [];
    $newoutcomearr = '';
    if ($src['outcomes'] != '' && !empty($opts['outcomes'])) {
        $stm = $DBH->prepare('SELECT id,name,ancestors FROM imas_outcomes WHERE courseid=?');
        $stm->execute([$sourcecid]);
        $ins = $DBH->prepare('INSERT INTO imas_outcomes (courseid,name,ancestors) VALUES (?,?,?)');
        while ($r = $stm->fetch(PDO::FETCH_NUM)) {
            $ins->execute([$destcid, $r[1], $r[2] == '' ? $r[0] : $r[0] . ',' . $r[2]]);
            $outcomes[$r[0]] = $DBH->lastInsertId();
        }
        $outcomesarr = unserialize($src['outcomes']);
        if ($outcomesarr === false) {
            $outcomesarr = [];
        }
        $remap = function (&$arr) use (&$remap, $outcomes) {
            if (!is_array($arr)) {
                return;
            }
            foreach ($arr as $k => $v) {
                if (is_array($v)) {
                    $remap($arr[$k]['outcomes']);
                } else {
                    $arr[$k] = $outcomes[$v] ?? $v;
                }
            }
        };
        $remap($outcomesarr);
        $newoutcomearr = serialize($outcomesarr);
    }
    $GLOBALS['outcomes'] = $outcomes;

    $removewithdrawn = true;
    $usereplaceby = 'all';
    $newitems = [];
    require_once __DIR__ . '/../includes/copyiteminc.php';
    $convertAssessVer = $destUIver;
    $_POST['ctc'] = $sourcecid;
    $blockcnt = 1;
    copyallsub($items, '0', $newitems, $gbcats);
    doaftercopy($sourcecid, $newitems);

    $up = $DBH->prepare('UPDATE imas_courses SET itemorder=?,blockcnt=?,ancestors=?,outcomes=?,UIver=? WHERE id=?');
    $up->execute([serialize($newitems), $blockcnt, $ancestors, $newoutcomearr, $destUIver, $destcid]);

    $offlinerubrics = [];
    if (!empty($opts['offline'])) {
        $stm = $DBH->prepare('SELECT name,points,showdate,gbcategory,cntingb,tutoredit,rubric FROM imas_gbitems WHERE courseid=?');
        $stm->execute([$sourcecid]);
        $ins = $DBH->prepare('INSERT INTO imas_gbitems (courseid,name,points,showdate,gbcategory,cntingb,tutoredit) VALUES (?,?,?,?,?,?,?)');
        while ($r = $stm->fetch(PDO::FETCH_ASSOC)) {
            $cat = isset($gbcats[$r['gbcategory']]) ? $gbcats[$r['gbcategory']] : 0;
            $ins->execute([$destcid, $r['name'], $r['points'], $r['showdate'], $cat, $r['cntingb'], $r['tutoredit']]);
            if ($r['rubric'] > 0) {
                $offlinerubrics[$DBH->lastInsertId()] = $r['rubric'];
            }
        }
    }
    if (!empty($opts['rubrics'])) {
        copyrubrics($offlinerubrics);
    }
    $DBH->commit();
} catch (Throwable $e) {
    if ($DBH->inTransaction()) {
        $DBH->rollBack();
    }
    error_log('nxcopy: ' . $e->getMessage());
    nx_fail(500, 'The copy failed and nothing was kept: ' . $e->getMessage());
}

$count = function ($arr) use (&$count) {
    $n = 0;
    foreach ($arr as $it) {
        $n += is_array($it) ? $count($it['items'] ?? []) : 1;
    }
    return $n;
};
echo json_encode([
    'assessments' => (object) $assessnewid,
    'blocks' => (object) ($blockidmap ?? []),
    'items' => $count($newitems),
]);
