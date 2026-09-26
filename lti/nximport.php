<?php
/**
 * Import endpoint for imathas-nx. (Fork-only file.)
 *
 * The nx server (never a browser) hands the engine an export file to import, on the same HMAC
 * trust as lti/nxrelease.php (NX_HANDOFF_SECRET). nx owns the pages — the upload, the preview
 * of what's inside, which modules and items to take — and checks the teacher; the engine does
 * the writing, with its own rules.
 *
 *   POST multipart: token=<base64url(json)>.<base64url(hmac-sha256)>, file=<the export>
 *   json: { "action": "course"|"library", "userId": N, "sha256": "<hex of file>", "exp": T,
 *           course:  "courseId": N, "checked": true | ["<item key>" | "0-1-2" block path ...],
 *                    "options": { "update": 1|-1, "userights": -1|0|2|3|4, "importgbsetup": 0|1 }
 *           library: "libs": true | [<export library id>...], "parent": <library id or 0>,
 *                    "options": { "update": 1|-1, "userights": -1|0|2|3|4, "librights": 0|2|4|8 } }
 *   200 { "counts": { label: n, ... } }
 *
 * "course" is IMathAS's own Import Course Items (admin/importitems2.php → ImportItemClass): a
 * course-items JSON export (MyOpenMath's "Export Course Items"), its folders, assessments with
 * their questions, text and links, appended to the course. Forums, wikis and drills in the
 * file come too (the stock importer takes whatever is checked); nx shows what it supports.
 *
 * "library" is IMathAS's admin-only Import Libraries (admin/importlib.php), adapted here for a
 * teacher: a question-library export (.imas, "PACKAGE DESCRIPTION"). The changes from the stock
 * page are about whose data a teacher may touch — an existing question is updated only on the
 * same rule the course importer uses (the teacher owns it, or it allows modification by all,
 * or they are an admin), and an existing library someone else owns is not added to: the
 * teacher gets their own copy of it.
 */
require_once __DIR__ . '/../init_without_validate.php';
require_once __DIR__ . '/../includes/filehandler.php';

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
if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    nx_fail(400, 'No file.');
}
$file = $_FILES['file']['tmp_name'];
// The token names the file: a different upload under a replayed token is refused.
if (!hash_equals((string) ($payload['sha256'] ?? ''), hash_file('sha256', $file))) {
    nx_fail(400, 'The file does not match the token.');
}

// The importing teacher, as the stock pages' validate.php would have set them.
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

$opts = is_array($payload['options'] ?? null) ? $payload['options'] : [];
$update = ((int) ($opts['update'] ?? 1)) === 1 ? 1 : -1;
$qrights = (int) ($opts['userights'] ?? -1);
if (!in_array($qrights, [-1, 0, 2, 3, 4], true)) {
    $qrights = -1;
}

$action = $payload['action'] ?? '';
if ($action === 'course') {
    $cid = (int) ($payload['courseId'] ?? 0);
    $stm = $DBH->prepare('SELECT c.UIver FROM imas_courses AS c JOIN imas_teachers AS t ON t.courseid=c.id WHERE c.id=? AND t.userid=?');
    $stm->execute([$cid, $userid]);
    $courseUIver = $stm->fetchColumn();
    if ($courseUIver === false) {
        nx_fail(403, 'You do not teach that course.');
    }
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data) || !isset($data['course']['itemorder'])) {
        nx_fail(400, 'This is not a course items export.');
    }
    if (!isset($data['course']['UIver'])) {
        $data['course']['UIver'] = 1;
    }
    if ((int) $data['course']['UIver'] > (int) $courseUIver) {
        nx_fail(400, 'The file is for a newer assessment version than this course.');
    }
    $checked = $payload['checked'] ?? true;
    if ($checked !== true) {
        $checked = array_values(array_map('strval', (array) $checked));
    }
    // The stock importer's includes are written relative to admin/.
    chdir(__DIR__ . '/../admin');
    require_once __DIR__ . '/../admin/itemexportfields.php';
    require_once __DIR__ . '/../admin/importitemsfuncs.php';
    $GLOBALS['db_fields'] = $db_fields;
    $options = [
        'update' => $update,
        'userights' => $qrights,
        'importlib' => 0,
    ];
    if (!empty($opts['importgbsetup'])) {
        $options['importgbsetup'] = 1;
    }
    try {
        $importer = new ImportItemClass();
        $res = $importer->importdata($data, $cid, $checked, $options);
    } catch (Throwable $e) {
        if ($DBH->inTransaction()) {
            $DBH->rollBack();
        }
        error_log('nximport course: ' . $e->getMessage());
        nx_fail(500, 'The import failed; nothing was added.');
    }
    echo json_encode(['counts' => $res]);
    exit;
}

if ($action !== 'library') {
    nx_fail(400, 'Unknown action.');
}

// ── Library import (adapted from admin/importlib.php) ─────────────────────────────────────

function nx_readline($h) {
    $l = gzgets($h, 1 << 20);
    return $l === false ? false : rtrim($l);
}

/** The libraries section of an .imas file (everything before the first question). */
function nx_parselibs($file) {
    $h = gzopen($file, 'r');
    $libs = [];
    $libitems = [];
    $sourceinstall = '';
    $libid = -1;
    $libitemid = -1;
    while (($line = nx_readline($h)) !== false && $line !== 'START QUESTION') {
        if ($line === 'INSTALLNAME') {
            $sourceinstall = nx_readline($h);
        } elseif ($line === 'START LIBRARY') {
            $libid = -1;
        } elseif ($line === 'ID') {
            $libid = nx_readline($h);
            $libs[$libid] = ['uid' => '0', 'lastmod' => 0, 'userights' => null, 'name' => '', 'parent' => '0'];
        } elseif ($libid !== -1 && $line === 'UID') {
            $libs[$libid]['uid'] = preg_replace('/[^0-9]/', '', nx_readline($h));
        } elseif ($libid !== -1 && $line === 'LASTMODDATE') {
            $libs[$libid]['lastmod'] = (int) nx_readline($h);
        } elseif ($libid !== -1 && $line === 'USERIGHTS') {
            $libs[$libid]['userights'] = (int) nx_readline($h);
        } elseif ($libid !== -1 && $line === 'NAME') {
            $libs[$libid]['name'] = nx_readline($h);
        } elseif ($libid !== -1 && $line === 'PARENT') {
            $libs[$libid]['parent'] = nx_readline($h);
        } elseif ($line === 'START LIBRARY ITEMS') {
            $libitemid = -1;
        } elseif ($line === 'LIBID') {
            $libitemid = nx_readline($h);
        } elseif ($line === 'QSETIDS' && $libitemid !== -1) {
            $libitems[$libitemid] = nx_readline($h);
        }
    }
    gzclose($h);
    return [$libs, $libitems, $sourceinstall];
}

/** Every question in the file, keyed by its export id. */
function nx_parseqs($file) {
    $parts = ['DESCRIPTION' => 'description', 'QID' => 'qid', 'UQID' => 'uqid', 'LASTMOD' => 'lastmod',
        'AUTHOR' => 'author', 'OWNERID' => 'ownerid', 'USERIGHTS' => 'userights', 'CONTROL' => 'control',
        'QCONTROL' => 'qcontrol', 'QTEXT' => 'qtext', 'QTYPE' => 'qtype', 'ANSWER' => 'answer',
        'SOLUTION' => 'solution', 'SOLUTIONOPTS' => 'solutionopts', 'EXTREF' => 'extref', 'LICENSE' => 'license',
        'ANCESTORAUTHORS' => 'ancestorauthors', 'OTHERATTRIBUTION' => 'otherattribution', 'QIMGS' => 'qimgs'];
    $h = gzopen($file, 'r');
    $qs = [];
    $q = null;
    $part = '';
    $flush = function () use (&$q, &$qs) {
        if ($q !== null) {
            foreach ($q as $k => $v) {
                $q[$k] = rtrim($v);
            }
            $qs[$q['qid']] = $q;
        }
    };
    while (($line = nx_readline($h)) !== false) {
        if ($line === 'START QUESTION') {
            $flush();
            $q = array_fill_keys(array_values($parts), '');
            $part = '';
        } elseif ($q !== null && isset($parts[$line])) {
            $part = $parts[$line];
        } elseif ($q !== null && $part !== '') {
            if ($part === 'qtype') {
                $q['qtype'] .= $line;
            } else {
                $q[$part] .= $line . "\n";
            }
        }
    }
    $flush();
    gzclose($h);
    return $qs;
}

/** The images a question names ("var,url[,alt]" lines), rehosted into qimages. */
function nx_writeqimgs($qsetid, $qd) {
    global $DBH;
    foreach (explode("\n", trim($qd['qimgs'])) as $qimg) {
        $p = explode(',', $qimg);
        if (count($p) < 2) {
            continue;
        }
        $alt = count($p) > 2 ? implode(',', array_slice($p, 2)) : '';
        if (strpos($qd['qtext'], '$' . $p[0]) === false && strpos($qd['qcontrol'], '$' . $p[0]) === false) {
            continue; // not actually used in the question
        }
        $fn = rehostfile($p[1], 'qimages', 'public', $qsetid . '-');
        if ($fn !== false) {
            $stm = $DBH->prepare('INSERT INTO imas_qimages (qsetid,var,filename,alttext) VALUES (?,?,?,?)');
            $stm->execute([$qsetid, $p[0], $fn, $alt]);
        }
    }
}

$parent = (int) ($payload['parent'] ?? 0);
if ($parent > 0) {
    $stm = $DBH->prepare('SELECT ownerid FROM imas_libraries WHERE id=? AND deleted=0');
    $stm->execute([$parent]);
    $powner = $stm->fetchColumn();
    if ($powner === false || ((int) $powner !== $userid && $myrights < 100)) {
        nx_fail(403, 'You can only import into a library you own.');
    }
}
$librights = (int) ($opts['librights'] ?? 0);
if (!in_array($librights, [0, 1, 2, 4, 5, 8], true)) {
    $librights = 0;
}

list($libs, $libitems, $sourceinstall) = nx_parselibs($file);
if (count($libs) === 0) {
    nx_fail(400, 'This is not a question library export (no libraries in it).');
}
$wanted = $payload['libs'] ?? true;
$libstoadd = $wanted === true ? array_keys($libs) : array_values(array_intersect(array_keys($libs), array_map('strval', (array) $wanted)));

$now = time();
$counts = ['New libraries' => 0, 'Questions added' => 0, 'Questions updated' => 0, 'Library items added' => 0];
$mt = microtime();
$newuid = function ($n) use ($mt) {
    return substr($mt, 11) . substr($mt, 2, 2) . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
};

$DBH->beginTransaction();
try {
    // Libraries, parents before children (the file lists them that way).
    $libmap = [];
    $uids = array_filter(array_map(function ($l) { return $l['uid']; }, $libs));
    $existing = [];
    if (count($uids)) {
        $ph = Sanitize::generateQueryPlaceholders($uids);
        $stm = $DBH->prepare("SELECT id,uniqueid,ownerid,deleted FROM imas_libraries WHERE uniqueid IN ($ph)");
        $stm->execute(array_values($uids));
        while ($row = $stm->fetch(PDO::FETCH_ASSOC)) {
            $existing[$row['uniqueid']] = $row;
        }
    }
    foreach ($libstoadd as $n => $lid) {
        $l = $libs[$lid];
        if ($l['parent'] === '0' || $l['parent'] === '') {
            $lparent = $parent;
        } elseif (isset($libmap[$l['parent']])) {
            $lparent = $libmap[$l['parent']];
        } else {
            continue; // its parent was left out, so it is too
        }
        $ex = $existing[$l['uid']] ?? null;
        if ($ex !== null && ((int) $ex['ownerid'] === $userid || $myrights == 100)) {
            if ((int) $ex['deleted'] === 1) {
                $stm = $DBH->prepare('UPDATE imas_libraries SET deleted=0,name=?,lastmoddate=? WHERE id=?');
                $stm->execute([Sanitize::stripHtmlTags($l['name']), $now, $ex['id']]);
            }
            $libmap[$lid] = (int) $ex['id'];
            continue;
        }
        $uid = ($ex === null && $l['uid'] !== '' && $l['uid'] !== '0') ? $l['uid'] : $newuid($n);
        $rights = ($l['userights'] !== null && !empty($opts['reuselibrights'])) ? $l['userights'] : $librights;
        $stm = $DBH->prepare('INSERT INTO imas_libraries (uniqueid,adddate,lastmoddate,name,ownerid,userights,parent,groupid) VALUES (?,?,?,?,?,?,?,?)');
        $stm->execute([$uid, $now, $now, Sanitize::stripHtmlTags($l['name']), $userid, $rights, $lparent, $groupid]);
        $libmap[$lid] = (int) $DBH->lastInsertId();
        $counts['New libraries']++;
    }

    // The questions those libraries hold.
    $touse = [];
    foreach ($libmap as $lid => $_) {
        if (!empty($libitems[$lid])) {
            $touse = array_merge($touse, explode(',', $libitems[$lid]));
        }
    }
    $touse = array_flip($touse);
    $qs = array_intersect_key(nx_parseqs($file), $touse);
    $qmap = [];
    foreach ($qs as $eid => $qd) {
        $hasimg = trim($qd['qimgs']) !== '' ? 1 : 0;
        $row = false;
        if ($qd['uqid'] !== '') {
            $stm = $DBH->prepare('SELECT id,lastmoddate,adddate,deleted,ownerid,userights FROM imas_questionset WHERE uniqueid=?');
            $stm->execute([preg_replace('/[^0-9]/', '', $qd['uqid'])]);
            $row = $stm->fetch(PDO::FETCH_ASSOC);
        }
        if ($row !== false) {
            $qmap[$eid] = (int) $row['id'];
            $mayEdit = (int) $row['ownerid'] === $userid || (int) $row['userights'] > 3 || $myrights == 100;
            $newer = (int) $qd['lastmod'] > (int) $row['adddate'] && (int) $row['lastmoddate'] <= (int) $row['adddate'];
            if ((int) $row['deleted'] === 1 || ($update === 1 && $newer && $mayEdit)) {
                $stm = $DBH->prepare('UPDATE imas_questionset SET description=?,author=?,qtype=?,control=?,qcontrol=?,qtext=?,answer=?,extref=?,license=?,ancestorauthors=?,otherattribution=?,solution=?,solutionopts=?,adddate=?,lastmoddate=?,hasimg=?,deleted=0 WHERE id=?');
                $stm->execute([$qd['description'], $qd['author'], $qd['qtype'], $qd['control'], $qd['qcontrol'], $qd['qtext'], $qd['answer'],
                    $qd['extref'], (int) $qd['license'], $qd['ancestorauthors'], $qd['otherattribution'], $qd['solution'], (int) $qd['solutionopts'],
                    $now, $now, $hasimg, $row['id']]);
                $counts['Questions updated']++;
                if ($hasimg) {
                    $DBH->prepare('DELETE FROM imas_qimages WHERE qsetid=?')->execute([$row['id']]);
                    nx_writeqimgs((int) $row['id'], $qd);
                }
            }
            continue;
        }
        $uqid = $qd['uqid'] !== '' ? preg_replace('/[^0-9]/', '', $qd['uqid']) : substr($mt, 11) . substr($mt, 2, 1) . str_pad((string) $eid, 5, '0', STR_PAD_LEFT);
        $rights = ($qrights === -1 && $qd['userights'] !== '') ? (int) $qd['userights'] : ($qrights === -1 ? 2 : $qrights);
        $stm = $DBH->prepare('INSERT INTO imas_questionset (uniqueid,adddate,lastmoddate,ownerid,userights,description,author,qtype,control,qcontrol,qtext,answer,solution,solutionopts,extref,license,ancestorauthors,otherattribution,hasimg) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stm->execute([$uqid, $now, $now, $userid, $rights, $qd['description'], $qd['author'], $qd['qtype'], $qd['control'], $qd['qcontrol'],
            $qd['qtext'], $qd['answer'], $qd['solution'], (int) $qd['solutionopts'], $qd['extref'], (int) $qd['license'],
            $qd['ancestorauthors'], $qd['otherattribution'], $hasimg]);
        $qmap[$eid] = (int) $DBH->lastInsertId();
        $counts['Questions added']++;
        if ($hasimg) {
            nx_writeqimgs($qmap[$eid], $qd);
        }
    }

    // includecodefrom(UID…) / includeqtextfrom(UID…) → this install's question ids.
    if (count($qmap)) {
        $ph = Sanitize::generateQueryPlaceholders($qmap);
        $stm = $DBH->prepare("SELECT id,control,qtext FROM imas_questionset WHERE id IN ($ph) AND (control LIKE '%includecodefrom(UID%' OR qtext LIKE '%includeqtextfrom(UID%')");
        $stm->execute(array_values($qmap));
        $rows = $stm->fetchAll(PDO::FETCH_ASSOC);
        $look = $DBH->prepare('SELECT id FROM imas_questionset WHERE uniqueid=? AND deleted=0');
        $resolve = function ($m) use ($look) {
            $look->execute([$m[2]]);
            $id = $look->fetchColumn();
            return $id === false ? $m[0] : $m[1] . '(' . $id . ')';
        };
        foreach ($rows as $row) {
            $control = preg_replace_callback('/(includecodefrom)\(UID(\d+)\)/', $resolve, $row['control']);
            $qtext = preg_replace_callback('/(includeqtextfrom)\(UID(\d+)\)/', $resolve, $row['qtext']);
            $DBH->prepare('UPDATE imas_questionset SET control=?,qtext=? WHERE id=?')->execute([$control, $qtext, $row['id']]);
        }
    }

    // Library items: each question into the libraries the file files it under.
    $has = $DBH->prepare('SELECT id,deleted FROM imas_library_items WHERE libid=? AND qsetid=?');
    $add = $DBH->prepare('INSERT INTO imas_library_items (libid,qsetid,ownerid,lastmoddate) VALUES (?,?,?,?)');
    $undel = $DBH->prepare('UPDATE imas_library_items SET deleted=0,ownerid=?,lastmoddate=? WHERE id=?');
    foreach ($libmap as $lid => $newlib) {
        if (empty($libitems[$lid])) {
            continue;
        }
        foreach (explode(',', $libitems[$lid]) as $eid) {
            if (!isset($qmap[$eid])) {
                continue;
            }
            $has->execute([$newlib, $qmap[$eid]]);
            $li = $has->fetch(PDO::FETCH_ASSOC);
            if ($li === false) {
                $add->execute([$newlib, $qmap[$eid], $userid, $now]);
                $counts['Library items added']++;
            } elseif ((int) $li['deleted'] === 1) {
                $undel->execute([$userid, $now, $li['id']]);
                $counts['Library items added']++;
            }
        }
    }
    // A question now in a real library leaves "Unassigned".
    $stm = $DBH->prepare('UPDATE imas_library_items AS A JOIN imas_library_items AS B ON A.qsetid=B.qsetid SET A.deleted=1,A.lastmoddate=? WHERE A.libid=0 AND A.deleted=0 AND B.libid>0 AND B.deleted=0');
    $stm->execute([$now]);
    $DBH->commit();
} catch (Throwable $e) {
    if ($DBH->inTransaction()) {
        $DBH->rollBack();
    }
    error_log('nximport library: ' . $e->getMessage());
    nx_fail(500, 'The import failed; nothing was added.');
}
echo json_encode(['counts' => $counts, 'libraries' => array_values($libmap)]);
