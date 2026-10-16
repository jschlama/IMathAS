<?php
/**
 * Copy assessments into a course for imathas-nx. (Fork-only file.)
 *
 * "Copy this assessment to another course" (or a second copy in the same course): the nx
 * server asks the engine, server to server, to copy the chosen assessments from one course
 * into another that already has items — on the same HMAC trust as lti/nxcopy.php
 * (NX_HANDOFF_SECRET).
 *
 *   POST token=<base64url(json)>.<base64url(hmac-sha256)>
 *   json: { "action": "copyitems", "userId": N, "sourceCourseId": N, "destCourseId": N,
 *           "assessmentIds": [N...], "module": <top-level block id, 0 = no module>,
 *           "where": "top"|"bottom", "append": " (copy)", "hidden": bool, "exp": T }
 *   200 { "assessments": { "<old id>": <new id>, ... } }
 *
 * The copy is course/copyitems.php's "copy" with selected items (whattocopy=select), the same
 * steps: gradebook categories and outcomes matched by NAME in the destination (no gradebook
 * setup copied), withdrawn questions left out and replaced questions swapped for their
 * replacements, each item through includes/copyiteminc.php (copysub + doaftercopy), the
 * questions' rubrics copied, and the new items added to the destination's itemorder at the
 * top or bottom of the module chosen. One transaction.
 *
 * The teacher must teach BOTH courses (imas_teachers); they may be the same course.
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
if (($payload['action'] ?? '') !== 'copyitems') {
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

$ctc = (int) ($payload['sourceCourseId'] ?? 0);
$destcid = (int) ($payload['destCourseId'] ?? 0);
if ($ctc <= 0 || $destcid <= 0) {
    nx_fail(400, 'Missing courses.');
}
$teaches = $DBH->prepare('SELECT 1 FROM imas_teachers WHERE userid=? AND courseid=?');
foreach (array_unique([$ctc, $destcid]) as $c) {
    $teaches->execute([$userid, $c]);
    if ($teaches->fetchColumn() === false) {
        nx_fail(403, 'You do not teach both courses.');
    }
}

// The items to copy: the imas_items rows of these assessments in the SOURCE course.
$aids = array_values(array_unique(array_filter(array_map('intval', (array) ($payload['assessmentIds'] ?? [])))));
if (count($aids) === 0) {
    nx_fail(400, 'No assessments named.');
}
$ph = Sanitize::generateQueryPlaceholders($aids);
$stm = $DBH->prepare("SELECT ii.id FROM imas_items AS ii JOIN imas_assessments AS ia ON ia.id=ii.typeid WHERE ii.itemtype='Assessment' AND ii.courseid=? AND ia.courseid=? AND ia.id IN ($ph)");
$stm->execute(array_merge([$ctc, $ctc], $aids));
$checked = array_map('strval', $stm->fetchAll(PDO::FETCH_COLUMN, 0));
if (count($checked) !== count($aids)) {
    nx_fail(404, 'An assessment named is not in that course.');
}

$stm = $DBH->prepare('SELECT itemorder, UIver, blockcnt, dates_by_lti FROM imas_courses WHERE id=?');
$stm->execute([$ctc]);
$src = $stm->fetch(PDO::FETCH_ASSOC);
$stm->execute([$destcid]);
$dst = $stm->fetch(PDO::FETCH_ASSOC);
if ($src === false || $dst === false) {
    nx_fail(404, 'No such course.');
}
$module = (int) ($payload['module'] ?? 0);
$where = ($payload['where'] ?? 'bottom') === 'top' ? 'top' : 'bottom';
$_POST['append'] = str_replace(['<', '>'], '', mb_substr((string) ($payload['append'] ?? ''), 0, 40));
$sethidden = !empty($payload['hidden']);

// ---- From here, course/copyitems.php "copy" with selected items, step for step. ----
$cid = $destcid; // copyiteminc.php writes into global $cid
$GLOBALS['cid'] = $cid;
$sourcecid = $ctc;
$datesbylti = (int) $dst['dates_by_lti'];
$GLOBALS['datesbylti'] = $datesbylti;
$blockcnt = (int) $dst['blockcnt'];
$_POST['ctc'] = $ctc;

$DBH->beginTransaction();
try {
    // Gradebook categories and outcomes: matched by name, nothing created (no "copy gb setup").
    $gbcats = [];
    $stm = $DBH->prepare('SELECT tc.id,toc.id FROM imas_gbcats AS tc JOIN imas_gbcats AS toc ON tc.name=toc.name WHERE tc.courseid=? AND toc.courseid=?');
    $stm->execute([$ctc, $destcid]);
    while ($row = $stm->fetch(PDO::FETCH_NUM)) {
        $gbcats[$row[0]] = $row[1];
    }
    $outcomes = [];
    $stm = $DBH->prepare('SELECT tc.id,toc.id FROM imas_outcomes AS tc JOIN imas_outcomes AS toc ON tc.name=toc.name WHERE tc.courseid=? AND toc.courseid=?');
    $stm->execute([$ctc, $destcid]);
    while ($row = $stm->fetch(PDO::FETCH_NUM)) {
        $outcomes[$row[0]] = $row[1];
    }
    $GLOBALS['outcomes'] = $outcomes;

    // removewithdrawn + usereplaceby (copyitems.php's defaults), set before copyiteminc.php reads them.
    $removewithdrawn = true;
    $usereplaceby = 'all';
    $replacebyarr = [];
    $stm = $DBH->prepare('SELECT imas_questionset.id,imas_questionset.replaceby FROM imas_questionset JOIN
        imas_questions ON imas_questionset.id=imas_questions.questionsetid JOIN
        imas_assessments ON imas_assessments.id=imas_questions.assessmentid WHERE
        imas_assessments.courseid=? AND imas_questionset.replaceby>0');
    $stm->execute([$ctc]);
    while ($row = $stm->fetch(PDO::FETCH_NUM)) {
        $replacebyarr[$row[0]] = $row[1];
    }
    $copystickyposts = false;
    require_once __DIR__ . '/../includes/copyiteminc.php';
    if ($ctc != $destcid) {
        prepopulate_forumtrack($ctc, $destcid);
    }

    $items = unserialize($src['itemorder']);
    if (!is_array($items)) {
        $items = [];
    }
    $newitems = [];
    // indicate this course's assess version so settings can be migrated
    $convertAssessVer = max(2, (int) $dst['UIver']);
    copysub($items, '0', $newitems, $gbcats, $sethidden);
    doaftercopy($ctc, $newitems);
    copyrubrics();

    // Into the destination's itemorder (read now: copying may have written blockcnt).
    $stm = $DBH->prepare('SELECT itemorder FROM imas_courses WHERE id=? FOR UPDATE');
    $stm->execute([$destcid]);
    $destitems = unserialize($stm->fetchColumn(0));
    if (!is_array($destitems)) {
        $destitems = [];
    }
    $placed = false;
    if ($module > 0) {
        foreach ($destitems as $k => $it) {
            if (is_array($it) && (int) ($it['id'] ?? 0) === $module) {
                if (!isset($destitems[$k]['items']) || !is_array($destitems[$k]['items'])) {
                    $destitems[$k]['items'] = [];
                }
                array_splice($destitems[$k]['items'], $where === 'top' ? 0 : count($destitems[$k]['items']), 0, $newitems);
                $placed = true;
                break;
            }
        }
        if (!$placed) {
            throw new Exception('That module is no longer in the course.');
        }
    } else {
        array_splice($destitems, $where === 'top' ? 0 : count($destitems), 0, $newitems);
    }
    $up = $DBH->prepare('UPDATE imas_courses SET itemorder=?,blockcnt=? WHERE id=?');
    $up->execute([serialize($destitems), $blockcnt, $destcid]);
    $DBH->commit();
} catch (Throwable $e) {
    if ($DBH->inTransaction()) {
        $DBH->rollBack();
    }
    error_log('nxcopyitem: ' . $e->getMessage());
    nx_fail(500, 'The copy failed and nothing was kept: ' . $e->getMessage());
}

echo json_encode(['assessments' => (object) $assessnewid]);
