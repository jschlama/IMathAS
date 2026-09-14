<?php
/**
 * Trusted hand-off from the imathas-nx app into the assess2 player. (Fork-only file.)
 *
 * The new app owns the LTI launch and the availability window; once a launch is validated and
 * open, it redirects the browser here with a short-lived HMAC token. Because the two services
 * are on different hosts, the identity cannot travel in a cookie — it travels in this token,
 * signed with the secret both share (NX_HANDOFF_SECRET / lib/server/deeplink/handoff.ts).
 *
 * This does what lti/finishlogin.php + lti/resourcelink.php do for a resource launch: find or
 * create the imas user, link it (imas_ltiusers), enrol the student, set the $_SESSION keys
 * assess2 reads, and redirect into the player. It never validates LTI itself — the app did.
 *
 * TWO MODES, and the difference between them is a privilege boundary, not a convenience:
 *
 *   launch  (default) — identity is an LTI subject the PLATFORM vouched for. A user is
 *                       created and linked if new, and an instructor is ENROLLED into
 *                       imas_teachers, because the LMS just said they teach this course.
 *
 *   preview           — identity is an imas user id, from a password sign-in to the nx app.
 *                       NOTHING is created and NOTHING is enrolled. The user must ALREADY
 *                       have rights >= 20 and an existing imas_teachers row for the course,
 *                       or this refuses. Reusing the launch path here would let any teacher
 *                       become teacher of any course by previewing it — the auto-enrol is
 *                       only safe when a platform vouched for the claim.
 */
require_once __DIR__ . '/../init_without_validate.php';

$secret = getenv('NX_HANDOFF_SECRET');
if (empty($secret)) {
    http_response_code(500);
    exit('Hand-off is not configured on this server (NX_HANDOFF_SECRET).');
}

function b64url_decode($s) {
    return base64_decode(strtr($s, '-_', '+/'));
}

$token = $_GET['token'] ?? '';
$dot = strpos($token, '.');
if ($dot === false || $dot === 0) {
    http_response_code(400);
    exit('Bad hand-off token.');
}
$body = substr($token, 0, $dot);
$mac = substr($token, $dot + 1);
$expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $secret, true)), '+/', '-_'), '=');
if (!hash_equals($expected, $mac)) {
    http_response_code(401);
    exit('Hand-off token signature does not verify.');
}
$payload = json_decode(b64url_decode($body), true);
if (!is_array($payload) || ($payload['exp'] ?? 0) < time()) {
    http_response_code(401);
    exit('Hand-off token is expired or malformed.');
}

$mode = ($payload['mode'] ?? 'launch') === 'preview' ? 'preview' : 'launch';
$sub = (string) ($payload['sub'] ?? '');
$platform_id = (int) ($payload['platformId'] ?? 0);
$role = ($payload['role'] ?? 'learner') === 'instructor' ? 'instructor' : 'learner';
$courseid = (int) ($payload['courseId'] ?? 0);
$aid = (int) ($payload['assessmentId'] ?? 0);
$first = (string) ($payload['firstName'] ?? '');
$last = (string) ($payload['lastName'] ?? '');
$email = (string) ($payload['email'] ?? '');
$tzoffset = (float) ($payload['tzoffset'] ?? 0);
$lticourseid = (int) ($payload['ltiCourseId'] ?? 0);
if ($courseid === 0 || $aid === 0) {
    http_response_code(400);
    exit('Hand-off token is missing required fields.');
}
if ($mode === 'launch' && $sub === '') {
    http_response_code(400);
    exit('Hand-off token is missing required fields.');
}

if ($mode === 'preview') {
    // A teacher previewing their own assessment from the nx app. The token names an EXISTING
    // imas user; we verify rather than trust, and we create nothing.
    // The session is always an instructor one here, whatever the token's role said, so
    // $_SESSION['ltirole'] below cannot disagree with the checks this branch just made.
    $role = 'instructor';
    $userid = (int) ($payload['userId'] ?? 0);
    if ($userid <= 0) {
        http_response_code(400);
        exit('Preview hand-off is missing the user id.');
    }
    $stm = $DBH->prepare('SELECT rights FROM imas_users WHERE id=?');
    $stm->execute([$userid]);
    $rights = $stm->fetchColumn();
    if ($rights === false || (int) $rights < 20) {
        http_response_code(403);
        exit('Preview is for instructor accounts only.');
    }
    // Teaching THIS course is the authorisation, and it must already be true. Never insert.
    $stm = $DBH->prepare('SELECT id FROM imas_teachers WHERE userid=? AND courseid=?');
    $stm->execute([$userid, $courseid]);
    if ($stm->fetchColumn() === false) {
        http_response_code(403);
        exit('That account does not teach this course.');
    }
    // The assessment must belong to the course too, or the course check proves nothing.
    $stm = $DBH->prepare('SELECT id FROM imas_assessments WHERE id=? AND courseid=?');
    $stm->execute([$aid, $courseid]);
    if ($stm->fetchColumn() === false) {
        http_response_code(404);
        exit('That assessment is not in this course.');
    }
} else {

// Find or create the imas user, exactly as finishlogin.php does (trace §1).
$org = 'LTI13-' . $platform_id;
$stm = $DBH->prepare('SELECT lti.userid FROM imas_ltiusers lti JOIN imas_users u ON lti.userid=u.id WHERE lti.ltiuserid=? AND lti.org=?');
$stm->execute([$sub, $org]);
$userid = $stm->fetchColumn();
if ($userid === false) {
    require_once __DIR__ . '/../includes/password.php';
    $rights = $role === 'instructor' ? 20 : 10;
    $stm = $DBH->prepare('INSERT INTO imas_users (SID,password,rights,groupid,FirstName,LastName,email) VALUES (?,?,?,0,?,?,?)');
    $stm->execute(['tmp' . uniqid(), password_hash(bin2hex(random_bytes(9)), PASSWORD_DEFAULT), $rights, $first, $last, $email]);
    $userid = (int) $DBH->lastInsertId();
    $DBH->prepare('UPDATE imas_users SET SID=? WHERE id=?')->execute(['lti-' . $userid, $userid]);
    $DBH->prepare('INSERT INTO imas_ltiusers (userid,ltiuserid,org) VALUES (?,?,?)')->execute([$userid, $sub, $org]);
}
$userid = (int) $userid;

// Enrol: a learner in imas_students, an instructor in imas_teachers (only if not already).
// Launch mode only — see the two-mode note at the top of this file.
if ($role === 'instructor') {
    $stm = $DBH->prepare('SELECT id FROM imas_teachers WHERE userid=? AND courseid=?');
    $stm->execute([$userid, $courseid]);
    if ($stm->fetchColumn() === false) {
        $DBH->prepare('INSERT INTO imas_teachers (userid,courseid) VALUES (?,?)')->execute([$userid, $courseid]);
    }
} else {
    // Enrol the student and stamp lticourseid so IMathAS's passback can build the sourcedid
    // (AssessUtils::formLTIsourcedId needs imas_students.lticourseid; trace §3.4).
    $stm = $DBH->prepare('SELECT id,lticourseid FROM imas_students WHERE userid=? AND courseid=?');
    $stm->execute([$userid, $courseid]);
    $srow = $stm->fetch(PDO::FETCH_ASSOC);
    if ($srow === false) {
        $DBH->prepare('INSERT INTO imas_students (userid,courseid,section,lticourseid) VALUES (?,?,?,?)')
            ->execute([$userid, $courseid, '', $lticourseid]);
    } else if ($lticourseid > 0 && (int) $srow['lticourseid'] !== $lticourseid) {
        $DBH->prepare('UPDATE imas_students SET lticourseid=? WHERE id=?')->execute([$lticourseid, $srow['id']]);
    }
}

}   // end launch mode

$uiver = (int) ($DBH->query('SELECT UIver FROM imas_courses WHERE id=' . $courseid)->fetchColumn() ?: 2);

// Start the native session (DB-backed handler + full-host cookie set in init.php) and set the
// keys resourcelink.php sets for a resource launch (trace §1, assess2/loadassess.php reads them).
session_start();
if (isset($_SESSION['userid']) && $_SESSION['userid'] != $userid) {
    $_SESSION = [];
}
$_SESSION['userid'] = $userid;
$_SESSION['lti_user_id'] = $sub;
$_SESSION['ltiver'] = '1.3';
$_SESSION['ltiitemtype'] = 0;          // 'assess'
$_SESSION['ltiitemid'] = $aid;
$_SESSION['ltiitemver'] = $uiver;
$_SESSION['ltirole'] = $role;
$_SESSION['tzoffset'] = $tzoffset;
$_SESSION['time'] = time();
$_SESSION['started'] = time();
session_write_close();

header(sprintf('Location: %s/assess2/?cid=%d&aid=%d', $GLOBALS['basesiteurl'], $courseid, $aid));
exit;
