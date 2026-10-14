<?php
/**
 * Grade-changing endpoint for imathas-nx. (Fork-only file.)
 *
 * The nx server (never a browser) asks the engine to change grades, on the same HMAC trust as
 * lti/nxhandoff.php and lti/nxrelease.php (NX_HANDOFF_SECRET). nx decides WHAT to change (its
 * broken-question and near-full-credit scans, confirmed by the teacher); the engine does the
 * changing with its own code, so the records are re-totalled, the audit log is written and the
 * grade goes to the LMS exactly as the stock pages do it.
 *
 *   POST token=<base64url(json)>.<base64url(hmac-sha256)>
 *
 *   { "action": "withdraw", "courseId": N, "assessmentId": N, "teacherId": N,
 *     "questionIds": [N...], "exp": T }
 *     = course/addquestions2.php's "Withdraw" with "full credit" (withdrawtype=full), for
 *       assess2 (ver > 1) assessments: imas_questions.withdrawn=1, every record re-scored with
 *       AssessRecord::withdrawQuestions, and each grade sent to the LMS.
 *     200 { "withdrawn": [qid...], "records": n }
 *
 *   { "action": "override", "courseId": N, "assessmentId": N, "teacherId": N,
 *     "scores": { "<userid>": <points> | "" }, "exp": T }
 *     = assess2/gbsave.php's overall score override (scores={"gen": v}) per student;
 *       "" clears the override and the engine re-totals.
 *     200 { "results": { "<userid>": { "old": <points>|"" , "score": <new gradebook score> } },
 *           "skipped": { "<userid>": "<reason>" } }
 *
 *   { "action": "settings", "courseId": N, "assessmentId": N, "teacherId": N,
 *     "columns": { "<imas_assessments column>": value, ... }, "exp": T }
 *     = course/addassessment2.php's save, for the behaviour columns an nx preset decides (the
 *       allowlist below): the row is updated, a change to a taken assessment is audit-logged,
 *       teacher and tutor attempts are deleted, every student record is re-totalled (and
 *       converted when the scoring mode changes) with grades sent to the LMS, and question
 *       locks are cleared when the lock bit is off — the same steps, in the same order.
 *     200 { "changed": ["<column>", ...], "taken": bool }
 *
 * The teacher must teach the course (imas_teachers), and only enrolled students are touched.
 */
require_once __DIR__ . '/../init_without_validate.php';
require_once __DIR__ . '/../assess2/AssessInfo.php';
require_once __DIR__ . '/../assess2/AssessRecord.php';
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
$tid = (int) ($payload['teacherId'] ?? 0);
if (!in_array($action, ['withdraw', 'override', 'settings'], true) || $cid <= 0 || $aid <= 0 || $tid <= 0) {
    nx_fail(400, 'Missing or unknown fields.');
}
$stm = $DBH->prepare('SELECT ver, defpoints, itemorder FROM imas_assessments WHERE id=? AND courseid=?');
$stm->execute([$aid, $cid]);
$arow = $stm->fetch(PDO::FETCH_ASSOC);
if ($arow === false) {
    nx_fail(404, 'That assessment is not in this course.');
}
if ((int) $arow['ver'] < 2) {
    nx_fail(400, 'Only current (assess2) assessments can be changed from nx.');
}
$stm = $DBH->prepare('SELECT 1 FROM imas_teachers WHERE userid=? AND courseid=?');
$stm->execute([$tid, $cid]);
if ($stm->fetchColumn() === false) {
    nx_fail(403, 'That teacher does not teach this course.');
}
// Who the audit log names, and the course globals validate.php sets for a page request.
$GLOBALS['userid'] = $tid;
$userid = $tid;
$stm = $DBH->prepare('SELECT enddate, latepasshrs FROM imas_courses WHERE id=?');
$stm->execute([$cid]);
$crow = $stm->fetch(PDO::FETCH_ASSOC);
$GLOBALS['courseenddate'] = $courseenddate = (int) $crow['enddate'];
$GLOBALS['latepasshrs'] = $latepasshrs = max(1, (int) $crow['latepasshrs']);

if ($action === 'withdraw') {
    // --- course/addquestions2.php, withdrawtype=full, assess2 branch ---
    $want = array_values(array_unique(array_filter(array_map('intval', (array) ($payload['questionIds'] ?? [])))));
    if (count($want) === 0) {
        nx_fail(400, 'No questions named.');
    }
    // Only questions of THIS assessment.
    $ph = Sanitize::generateQueryPlaceholders($want);
    $stm = $DBH->prepare("SELECT id FROM imas_questions WHERE assessmentid=? AND id IN ($ph)");
    $stm->execute([$aid, ...$want]);
    $qids = array_map('intval', $stm->fetchAll(PDO::FETCH_COLUMN, 0));
    if (count($qids) !== count($want)) {
        nx_fail(400, 'A question named is not in this assessment.');
    }
    $qidlist = implode(',', $qids);
    $DBH->query("UPDATE imas_questions SET withdrawn=1 WHERE id IN ($qidlist)");
    TeacherAuditLog::addTracking(
        $cid,
        "Assessment Settings Change",
        $aid,
        array('withdrawquestions' => $qids, 'withdrawtype' => 'full', 'via' => 'imathas-nx')
    );
    $poss = array();
    $stm = $DBH->query("SELECT id,points FROM imas_questions WHERE id IN ($qidlist)");
    while ($row = $stm->fetch(PDO::FETCH_NUM)) {
        $poss[$row[0]] = ($row[1] == 9999) ? $arow['defpoints'] : $row[1];
    }
    $assess_info = new AssessInfo($DBH, $aid, $cid, false);
    $assess_info->loadQuestionSettings();
    $n = 0;
    $DBH->beginTransaction();
    $stm = $DBH->prepare("SELECT * FROM imas_assessment_records WHERE assessmentid=? FOR UPDATE");
    $stm->execute(array($aid));
    while ($row = $stm->fetch(PDO::FETCH_ASSOC)) {
        $assess_record = new AssessRecord($DBH, $assess_info, false);
        $assess_record->setRecord($row);
        $updatedScore = $assess_record->withdrawQuestions($poss);
        $assess_record->saveRecordIfNeeded();
        $assess_record->setInPractice(true);
        $assess_record->withdrawQuestions($poss);
        $assess_record->saveRecordIfNeeded();
        if (strlen($row['lti_sourcedid']) > 1) {
            require_once __DIR__ . '/../includes/ltioutcomes.php';
            calcandupdateLTIgrade($row['lti_sourcedid'], $aid, $row['userid'], $updatedScore, true, -1, false);
        }
        $n++;
    }
    $DBH->commit();
    echo json_encode(['withdrawn' => $qids, 'records' => $n]);
    exit;
}

if ($action === 'settings') {
    // The columns an nx preset decides (presets/settings.ts toEngine), and nothing else: name,
    // dates, points and content are written by nx directly, as they need no re-total.
    $allowed = array(
        'submitby' => ['by_question', 'by_assessment'],
        'defattempts' => 'int', 'defpenalty' => 'str', 'defregens' => 'int',
        'defregenpenalty' => 'str', 'keepscore' => ['best', 'last', 'average'],
        'retakewait' => 'int', 'showscores' => 'str', 'showans' => 'str', 'viewingb' => 'str',
        'scoresingb' => 'str', 'ansingb' => 'str', 'showhints' => 'int', 'showtips' => 'int',
        'noprint' => 'int', 'shuffle' => 'int', 'timelimit' => 'int', 'overtime_grace' => 'int',
        'overtime_penalty' => 'int', 'reviewdate' => 'int', 'cntingb' => 'int',
        'allowlate' => 'int', 'exceptionpenalty' => 'int', 'exceptionpenaltyinterval' => 'int'
    );
    $cols = (array) ($payload['columns'] ?? []);
    $toset = array();
    foreach ($cols as $k => $v) {
        if (!isset($allowed[$k])) {
            nx_fail(400, "Column $k cannot be set here.");
        }
        $rule = $allowed[$k];
        if (is_array($rule) ? !in_array($v, $rule, true)
            : ($rule === 'int' ? !is_int($v) : !is_string($v))) {
            nx_fail(400, "Bad value for $k.");
        }
        $toset[$k] = $v;
    }
    if (count($toset) === 0) {
        nx_fail(400, 'No columns named.');
    }
    $stm = $DBH->prepare('SELECT * FROM imas_assessments WHERE id=?');
    $stm->execute([$aid]);
    $curassess = $stm->fetch(PDO::FETCH_ASSOC);
    // addassessment2.php's $taken: an enrolled student has a record.
    $stm = $DBH->prepare('SELECT COUNT(iar.userid) FROM imas_assessment_records AS iar,imas_students WHERE
        iar.assessmentid=? AND iar.userid=imas_students.userid AND imas_students.courseid=?');
    $stm->execute([$aid, $cid]);
    $taken = ($stm->fetchColumn(0) > 0);

    $DBH->beginTransaction();
    $sets = array();
    $qarr = array();
    foreach ($toset as $k => $v) {
        $sets[] = "$k=:$k";
        $qarr[":$k"] = $v;
    }
    $qarr[':id'] = $aid;
    $qarr[':cid'] = $cid;
    $stm = $DBH->prepare('UPDATE imas_assessments SET ' . implode(',', $sets) . ' WHERE id=:id AND courseid=:cid');
    $stm->execute($qarr);
    $changed = array();
    foreach ($curassess as $k => $v) {
        if (isset($toset[$k]) && $toset[$k] != $v) {
            $changed[$k] = ['old' => $v, 'new' => $toset[$k]];
        }
    }
    if ($taken && count($changed) > 0) {
        TeacherAuditLog::addTracking($cid, "Assessment Settings Change", $aid,
            array_merge($changed, ['via' => 'imathas-nx']));
    }
    // Delete any teacher or tutor attempts on this assessment
    $stm = $DBH->prepare('DELETE iar FROM imas_assessment_records AS iar JOIN
        imas_teachers AS usr ON usr.userid=iar.userid AND usr.courseid=? WHERE iar.assessmentid=?');
    $stm->execute(array($cid, $aid));
    $stm = $DBH->prepare('DELETE iar FROM imas_assessment_records AS iar JOIN
        imas_tutors AS usr ON usr.userid=iar.userid AND usr.courseid=? WHERE iar.assessmentid=?');
    $stm->execute(array($cid, $aid));
    // Re-total any student attempts on this assessment (converting them on a scoring change)
    require_once __DIR__ . '/../assess2/AssessHelpers.php';
    require_once __DIR__ . '/../includes/ltioutcomes.php';
    $newsubmitby = $toset['submitby'] ?? $curassess['submitby'];
    AssessHelpers::retotalAll($cid, $aid, true, false,
        ($newsubmitby == $curassess['submitby']) ? '' : $newsubmitby, false);
    $noprint = $toset['noprint'] ?? (int) $curassess['noprint'];
    if (($noprint & 2) === 0) {
        // no lock: clear any existing locks
        $stm = $DBH->prepare("UPDATE imas_students SET lockaid=0 WHERE courseid=? AND lockaid=?");
        $stm->execute([$cid, $aid]);
    }
    $DBH->commit();
    echo json_encode(['changed' => array_keys($changed), 'taken' => $taken]);
    exit;
}

// --- action override: assess2/gbsave.php, scores={"gen": v}, one student at a time ---
$scores = (array) ($payload['scores'] ?? []);
if (count($scores) === 0) {
    echo json_encode(['results' => new stdClass(), 'skipped' => new stdClass()]);
    exit;
}
$results = array();
$skipped = array();
$query = 'SELECT istu.latepass, istu.timelimitmult FROM imas_students AS istu WHERE istu.userid=? AND istu.courseid=?';
$stustm = $DBH->prepare($query);
foreach ($scores as $uidKey => $value) {
    $uid = (int) $uidKey;
    if ($uid <= 0 || !($value === '' || is_int($value) || is_float($value))) {
        $skipped[$uidKey] = 'bad_value';
        continue;
    }
    $stustm->execute(array($uid, $cid));
    $studata = $stustm->fetch(PDO::FETCH_ASSOC);
    if ($studata === false) {
        $skipped[$uidKey] = 'not_enrolled';
        continue;
    }
    // A fresh AssessInfo per student: loadException sets that student's dates on it.
    $assess_info = new AssessInfo($DBH, $aid, $cid, false);
    $assess_info->loadException($uid, true, $studata['latepass'], $latepasshrs, $courseenddate);
    $assess_info->applyTimelimitMultiplier($studata['timelimitmult']);
    $assess_info->loadQuestionSettings('all', false, false);
    $assess_record = new AssessRecord($DBH, $assess_info, false);
    $assess_record->loadRecord($uid);
    $assess_record->setInGb(true);
    if (!$assess_record->hasRecord()) {
        $skipped[$uidKey] = 'no_record';
        continue;
    }
    $changes = $assess_record->setGbScoreOverrides(array('gen' => $value === '' ? '' : (float) $value));
    $assess_record->saveRecord();
    if (!empty($changes)) {
        TeacherAuditLog::addTracking(
            $cid,
            "Change Grades",
            $aid,
            array('stu' => $uid, 'overrides' => $changes, 'via' => 'imathas-nx')
        );
    }
    $assess_record->updateLTIscore(true, false);
    $gb = $assess_record->getGbScore();
    $results[$uidKey] = array(
        'old' => $changes['gen']['old'] ?? '',
        'score' => (float) $gb['gbscore']
    );
}
echo json_encode([
    'results' => count($results) ? $results : new stdClass(),
    'skipped' => count($skipped) ? $skipped : new stdClass()
]);
