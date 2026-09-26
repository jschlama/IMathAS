<?php
/**
 * Release endpoint for imathas-nx. (Fork-only file.)
 *
 * The nx server (never a browser) asks the engine to release something for a list of
 * students, on the same HMAC trust as lti/nxhandoff.php (NX_HANDOFF_SECRET). nx decides WHO
 * and WHEN (its per-section windows, docs/sections-design.md §4); the engine does the
 * releasing, with its own functions.
 *
 *   POST token=<base64url(json)>.<base64url(hmac-sha256)>
 *   json: { "action": "grades", "courseId": N, "assessmentId": N, "userIds": [N...], "exp": T }
 *   200 { "released": n }
 *
 * "grades" sets status2 bit 2 (grade released to the LMS) and sends the grade now; scores and
 * answers stay held for the student. Only students enrolled in the course are touched.
 */
require_once __DIR__ . '/../init_without_validate.php';
require_once __DIR__ . '/../assess2/AssessInfo.php';
require_once __DIR__ . '/../assess2/AssessRecord.php';
require_once __DIR__ . '/../assess2/AssessHelpers.php';
require_once __DIR__ . '/../includes/TeacherAuditLog.php';

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
$cid = (int) ($payload['courseId'] ?? 0);
$aid = (int) ($payload['assessmentId'] ?? 0);
$uids = array_values(array_unique(array_filter(array_map('intval', (array) ($payload['userIds'] ?? [])))));
if ($action !== 'grades' || $cid <= 0 || $aid <= 0) {
    nx_fail(400, 'Missing or unknown fields.');
}
$stm = $DBH->prepare('SELECT id FROM imas_assessments WHERE id=? AND courseid=?');
$stm->execute([$aid, $cid]);
if ($stm->fetchColumn() === false) {
    nx_fail(404, 'That assessment is not in this course.');
}
// The course globals validate.php sets for a page request; AssessInfo reads them.
$stm = $DBH->prepare('SELECT enddate, latepasshrs FROM imas_courses WHERE id=?');
$stm->execute([$cid]);
$crow = $stm->fetch(PDO::FETCH_ASSOC);
$GLOBALS['courseenddate'] = (int) $crow['enddate'];
$GLOBALS['latepasshrs'] = max(1, (int) $crow['latepasshrs']);
if (count($uids) === 0) {
    echo json_encode(['released' => 0]);
    exit;
}
// Only students of this course: a forged list can't reach another course's records.
$ph = Sanitize::generateQueryPlaceholders($uids);
$stm = $DBH->prepare("SELECT userid FROM imas_students WHERE courseid=? AND userid IN ($ph)");
$stm->execute([$cid, ...$uids]);
$enrolled = array_map('intval', $stm->fetchAll(PDO::FETCH_COLUMN, 0));

$n = AssessHelpers::releaseGrades($cid, $aid, $enrolled);
echo json_encode(['released' => $n]);
