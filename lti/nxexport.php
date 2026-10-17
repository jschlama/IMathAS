<?php
/**
 * Export course items for imathas-nx. (Fork-only file.)
 *
 * IMathAS's own Export Course Items (admin/exportitems2.php), run for an nx teacher. nx shows
 * the course's folders and items to tick and POSTs them here in a new tab, with a token on the
 * same HMAC trust as the other nx endpoints (NX_HANDOFF_SECRET), purpose 'export', naming the
 * course and the teacher. nx signs one only for a teacher of that course.
 *
 * The export itself is exportitems2.php's step 2, unchanged: this page only stands in for its
 * login (init.php), the way the engine's own session would — the course from the token, never
 * from the request — and the file comes back as the engine sends it (imasitemexport-<cid>.imas,
 * the JSON that Import Course Items reads). The ticked keys can only ever select this course's
 * items: the export walks THIS course's itemorder and copies what matches.
 */
define('NX_EXPORT', 1);
$init_skip_csrfp = true;
require_once __DIR__ . '/../init_without_validate.php';
ini_set('display_errors', '0');

$secret = getenv('NX_HANDOFF_SECRET');
if (empty($secret)) { http_response_code(500); exit('Export not configured.'); }

function nx_export_b64url_decode($s) { return base64_decode(strtr($s, '-_', '+/')); }

$token = $_POST['token'] ?? '';
$dot = strpos($token, '.');
if ($dot === false || $dot === 0) { http_response_code(400); exit('Bad token.'); }
$body = substr($token, 0, $dot);
$expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $secret, true)), '+/', '-_'), '=');
if (!hash_equals($expected, substr($token, $dot + 1))) { http_response_code(401); exit('Bad token signature.'); }
$claims = json_decode(nx_export_b64url_decode($body), true);
if (!is_array($claims) || ($claims['exp'] ?? 0) < time() || ($claims['purpose'] ?? '') !== 'export') {
    http_response_code(401); exit('This export link has expired. Go back and press Export again.');
}

$cid = (int) ($claims['cid'] ?? 0);
$uid = (int) ($claims['uid'] ?? 0);
$stm = $DBH->prepare('SELECT u.id, u.rights, c.name FROM imas_users AS u JOIN imas_courses AS c ON c.id=:cid
    WHERE u.id=:uid AND (c.ownerid=u.id OR EXISTS (SELECT 1 FROM imas_teachers t WHERE t.courseid=c.id AND t.userid=u.id) OR u.rights=100)');
$stm->execute([':cid' => $cid, ':uid' => $uid]);
$who = $stm->fetch(PDO::FETCH_ASSOC);
if ($who === false) { http_response_code(403); exit('Not a teacher of this course.'); }

// What init.php would have set for this teacher in this course.
$userid = $uid;
$myrights = (int) $who['rights'];
$teacherid = $uid;
$coursename = $who['name'];
$breadcrumbbase = '';
$_GET['cid'] = $cid;
$_POST['export'] = 1;
// Only the choices nx offers ride through; everything else the stock page could take stays off.
$picked = $_POST['checked'] ?? [];
$_POST = [
    'export' => 1,
    'checked' => is_array($picked) ? array_values(array_map('strval', $picked)) : [],
    'excludecopyright' => !empty($_POST['excludecopyright']) ? 1 : null,
    'excludenc' => !empty($_POST['excludenc']) ? 1 : null
];
$_POST = array_filter($_POST, fn($v) => $v !== null);

require __DIR__ . '/../admin/exportitems2.php';
