<?php
/*
 * IMathAS NX: every student's work on one question, as JSON. (Fork-only file.)
 *
 * course/gradeallq2.php's display, without the page: for each enrolled student with a record,
 * each place the question sits in their assessment (getQuestionLocs - a pool or a shuffle puts
 * it at a different number for each student), the question rendered with their answer, its
 * parts, scores, feedback and shown work. Each rendering gets its own display number (dispqn)
 * so all of them can be mounted on one page, as gradeallq2 does with $cnt.
 *
 * Read-only apart from what gradeallq2 itself does on view (saveRecordIfNeeded, when building
 * the display filled in answer weights). Saving a grade is assess2/gbsave.php, per student.
 *
 * Method: GET
 *   cid, aid, qid   the course, assessment and imas_questions id
 *   ver             'scored' (default) or 'last', as gradeallq2
 *
 * Returns: { name, qid, qsetid, points, submitby, isgroup, students: [ { userid, name,
 *   section, locked, work: [html, time]|null, locs: [ {ver, qn, dispqn, ...question data} ] } ] }
 */

$no_session_handler = 'json_error';
$init_csrfp_scope = 'question';
require_once "../init.php";
require_once "./common_start.php";
require_once "./AssessInfo.php";
require_once "./AssessRecord.php";
require_once './AssessUtils.php';

header('Content-Type: application/json; charset=utf-8');

check_for_required('GET', array('aid', 'cid', 'qid'));
$cid = Sanitize::onlyInt($_GET['cid']);
$aid = Sanitize::onlyInt($_GET['aid']);
$qid = Sanitize::onlyInt($_GET['qid']);
$ver = (isset($_GET['ver']) && $_GET['ver'] === 'last') ? 'last' : 'scored';

if (!$isActualTeacher && !$istutor) {
  echo '{"error": "no_access"}';
  exit;
}

$stm = $DBH->prepare("SELECT name,isgroup,courseid,tutoredit,submitby,ver FROM imas_assessments WHERE id=:id");
$stm->execute(array(':id'=>$aid));
$arow = $stm->fetch(PDO::FETCH_ASSOC);
if ($arow === false || $arow['courseid'] != $cid) {
  echo '{"error": "invalid_aid"}';
  exit;
}
if ($arow['ver'] < 2) {
  echo '{"error": "old_assessment"}';
  exit;
}
if ($istutor && $arow['tutoredit'] == 2) {
  echo '{"error": "no_access"}';
  exit;
}
$stm = $DBH->prepare("SELECT 1 FROM imas_questions WHERE id=? AND assessmentid=?");
$stm->execute(array($qid, $aid));
if ($stm->fetchColumn() === false) {
  echo '{"error": "invalid_qid"}';
  exit;
}
$submitby = $arow['submitby'];

$assess_info = new AssessInfo($DBH, $aid, $cid, false);
$assess_info->loadQuestionSettings($qid, true, false);
$points = $assess_info->getQuestionSetting($qid, 'points_possible');

// a tutor limited to a section sees only that section, as in gradeallq2
$secfilter = (isset($tutorsection) && $tutorsection != '') ? $tutorsection : -1;

$exceptionarr = [];
$stm = $DBH->prepare("SELECT ie.* FROM imas_exceptions AS ie
  JOIN imas_students AS istu ON istu.userid=ie.userid
  WHERE istu.courseid=:courseid AND ie.assessmentid=:assessmentid");
$stm->execute([':courseid'=>$cid, ':assessmentid'=>$aid]);
while ($line = $stm->fetch(PDO::FETCH_ASSOC)) {
  $exceptionarr[$line['userid']] = $line;
}

$qarr = array(':courseid'=>$cid, ':assessmentid'=>$aid);
$query = "SELECT imas_users.LastName,imas_users.FirstName,imas_students.section,imas_students.locked,imas_assessment_records.* ";
$query .= "FROM imas_users,imas_assessment_records,imas_students ";
$query .= "WHERE imas_assessment_records.userid=imas_users.id AND imas_students.userid=imas_users.id ";
$query .= "AND imas_students.courseid=:courseid AND imas_assessment_records.assessmentid=:assessmentid ";
if ($submitby == 'by_assessment') {
  $query .= "AND (imas_assessment_records.status & 64)=64 ";
}
if ($secfilter != -1) {
  $query .= "AND imas_students.section=:section ";
  $qarr[':section'] = $secfilter;
}
$query .= "ORDER BY imas_users.LastName,imas_users.FirstName";
$stm = $DBH->prepare($query);
$stm->execute($qarr);

require_once "../includes/filehandler.php";
$cnt = 500;
$students = array();
while ($line = $stm->fetch(PDO::FETCH_ASSOC)) {
  $assess_info->setException($line['userid'], $exceptionarr[$line['userid']] ?? false, true);
  $assess_record = new AssessRecord($DBH, $assess_info, false);
  $assess_record->setRecord($line);
  $assess_record->setTeacherInGb(true);
  $GLOBALS['assessver'] = $line['ver'];
  $teacherreview = $line['userid'];

  $locdata = $assess_record->getQuestionLocs($qid, $ver);
  $singlework = null;
  if ($submitby != 'by_assessment') {
    $singlework = $assess_record->getGenShowwork('last');
  }
  $locs = array();
  foreach ($locdata as $vernum=>$lockeys) {
    if ($submitby == 'by_assessment') {
      $singlework = $assess_record->getGenShowwork($vernum);
    }
    foreach ($lockeys as $loc) {
      $qdata = $assess_record->getGbQuestionVersionData($loc, true, $vernum, $cnt);
      $qdata['ver'] = $vernum;
      $qdata['qn'] = $loc;
      $qdata['dispqn'] = $cnt;
      $locs[] = $qdata;
      $cnt++;
    }
  }
  $assess_record->saveRecordIfNeeded();
  if (count($locs) === 0) {
    continue;
  }
  $students[] = array(
    'userid' => intval($line['userid']),
    'name' => $line['LastName'] . ', ' . $line['FirstName'],
    'section' => $line['section'],
    'agroupid' => intval($line['agroupid']),
    'released' => (($line['status2'] & 1) == 1),
    'work' => (!empty($singlework[0])) ? $singlework : null,
    'locs' => $locs
  );
}

echo json_encode(array(
  'name' => $arow['name'],
  'qid' => $qid,
  'qsetid' => $assess_info->getQuestionSetting($qid, 'questionsetid'),
  'points' => $points,
  'submitby' => $submitby,
  'isgroup' => intval($arow['isgroup']),
  'students' => $students
), JSON_INVALID_UTF8_IGNORE);
