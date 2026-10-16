<?php
/**
 * Question images for imathas-nx's question editor. (Fork-only file.)
 *
 * The nx server (never a browser) asks the engine to store or remove a question's image, on
 * the same HMAC trust as lti/nxgrade.php (NX_HANDOFF_SECRET). The file lands where the engine
 * keeps question images (includes/filehandler.php: assessment/qimages, or S3), so the engine
 * serves it as it serves any other — nx never writes files.
 *
 *   POST (multipart) token=<base64url(json)>.<base64url(hmac-sha256)>, imgfile=<the image>
 *
 *   { "action": "add", "teacherId": N, "questionId": N, "var": "name", "alt": "text", "exp": T }
 *     = course/moddataset.php's image upload: the variable name cleaned the same way and
 *       checked against its reserved names, the file checked to be an image (or an SVG),
 *       stored with storeuploadedqimage(), an imas_qimages row, hasimg=1.
 *     200 { "id": N, "var": "name", "filename": "stored.png", "url": "<absolute url>" }
 *
 *   { "action": "delete", "teacherId": N, "questionId": N, "imageId": N, "exp": T }
 *     = moddataset.php's "Delete?" on an image: the file goes only when no other row uses it
 *       (a copied question shares its source's files), the row goes, hasimg=0 when none is left.
 *     200 { "deleted": N }
 *
 * The teacher must be allowed to change the question, by moddataset.php's rule.
 */
require_once __DIR__ . '/../init_without_validate.php';
require_once __DIR__ . '/../includes/filehandler.php';

header('Content-Type: application/json');
// A JSON endpoint: a warning printed ahead of the body would make the caller misread it.
ini_set('display_errors', '0');
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
$action = $payload['action'] ?? '';
$tid = (int) ($payload['teacherId'] ?? 0);
$qsetid = (int) ($payload['questionId'] ?? 0);
if (!in_array($action, ['add', 'delete'], true) || $tid <= 0 || $qsetid <= 0) {
    nx_fail(400, 'Missing or unknown fields.');
}

// moddataset.php's $myq: owner, full admin, group admin of the owner's group, the owner's group
// on "group may change" (3), anyone on "anyone may change" (4).
$stm = $DBH->prepare('SELECT iq.ownerid, iq.userights, iu.groupid FROM imas_questionset AS iq JOIN imas_users AS iu ON iu.id=iq.ownerid WHERE iq.id=? AND iq.deleted=0');
$stm->execute([$qsetid]);
$q = $stm->fetch(PDO::FETCH_ASSOC);
if ($q === false) {
    nx_fail(404, 'No such question.');
}
$stm = $DBH->prepare('SELECT rights, groupid FROM imas_users WHERE id=?');
$stm->execute([$tid]);
$me = $stm->fetch(PDO::FETCH_ASSOC);
if ($me === false) {
    nx_fail(403, 'No such teacher.');
}
$sameGroup = (int) $q['groupid'] === (int) $me['groupid'];
$mayEdit = (int) $q['ownerid'] === $tid
    || (int) $me['rights'] >= 100
    || ((int) $me['rights'] >= 75 && $sameGroup)
    || ((int) $q['userights'] === 3 && $sameGroup)
    || (int) $q['userights'] > 3;
if (!$mayEdit) {
    nx_fail(403, 'That teacher may not change this question.');
}

if ($action === 'add') {
    // --- course/moddataset.php, "upload image files if attached" ---
    $disallowedvar = ['link','qidx','qnidx','seed','qdata','toevalqtxt','la','laarr','shanspt','GLOBALS','laparts','anstype','kidx','iidx','tips','options','optionsPack','partla','partnum','score','disallowedvar','allowedmacros','wherecount','countcnt','myrights','myspecialrights'];
    $newvar = preg_replace('/[^\w\[\]]/', '', (string) ($payload['var'] ?? ''));
    if (trim($newvar) === '') {
        nx_fail(400, 'Give the image a variable name.');
    }
    if (in_array($newvar, $disallowedvar, true)) {
        nx_fail(400, "$newvar is not an allowed variable name.");
    }
    if (empty($_FILES['imgfile']['name']) || !is_uploaded_file($_FILES['imgfile']['tmp_name'])) {
        $err = $_FILES['imgfile']['error'] ?? 4;
        nx_fail(400, ($err == 1 || $err == 2) ? 'That file is too big.' : 'No image came with the request.');
    }
    $filename = preg_replace('/[^\w\.]/', '', basename(str_replace('\\', '/', $_FILES['imgfile']['name'])));
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mimetype = mime_content_type($_FILES['imgfile']['tmp_name']);
    $isSvg = $mimetype === 'image/svg+xml' && $extension === 'svg';
    if (getimagesize($_FILES['imgfile']['tmp_name']) === false && !$isSvg) {
        nx_fail(400, 'That file is not an image.');
    }
    if ($filename === '' || strpos($filename, '.') === false) {
        $filename = 'image.' . ($extension !== '' ? $extension : 'png');
    }
    $stored = storeuploadedqimage('imgfile', $filename);
    if ($stored === false) {
        nx_fail(500, 'The image could not be stored.');
    }
    $alt = Sanitize::stripHtmlTags((string) ($payload['alt'] ?? ''));
    $stm = $DBH->prepare('INSERT INTO imas_qimages (var,qsetid,filename,alttext) VALUES (?,?,?,?)');
    $stm->execute([$newvar, $qsetid, $stored, $alt]);
    $id = (int) $DBH->lastInsertId();
    $DBH->prepare('UPDATE imas_questionset SET hasimg=1 WHERE id=?')->execute([$qsetid]);
    echo json_encode(['id' => $id, 'var' => $newvar, 'filename' => $stored, 'url' => getqimageurl($stored, true)]);
    exit;
}

// --- delete: moddataset.php's "delimg-<id>" ---
$imgid = (int) ($payload['imageId'] ?? 0);
$stm = $DBH->prepare('SELECT id, filename FROM imas_qimages WHERE id=? AND qsetid=?');
$stm->execute([$imgid, $qsetid]);
$row = $stm->fetch(PDO::FETCH_ASSOC);
if ($row === false) {
    nx_fail(404, 'That image is not on this question.');
}
if (substr($row['filename'], 0, 4) !== 'http') {
    $stm = $DBH->prepare('SELECT COUNT(*) FROM imas_qimages WHERE filename=?');
    $stm->execute([$row['filename']]);
    if ((int) $stm->fetchColumn() === 1) {
        deleteqimage($row['filename']);
    }
}
$DBH->prepare('DELETE FROM imas_qimages WHERE id=?')->execute([$imgid]);
$stm = $DBH->prepare('SELECT COUNT(*) FROM imas_qimages WHERE qsetid=?');
$stm->execute([$qsetid]);
if ((int) $stm->fetchColumn() === 0) {
    $DBH->prepare('UPDATE imas_questionset SET hasimg=0 WHERE id=?')->execute([$qsetid]);
}
echo json_encode(['deleted' => $imgid]);
