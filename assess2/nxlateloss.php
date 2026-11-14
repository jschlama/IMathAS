<?php
/*
 * IMathAS NX: the points each student lost to the LATE penalty on one assessment, as JSON.
 * (Fork-only file.)
 *
 * imathas-nx's daily report lists who lost the most to late work. The record doesn't store
 * which penalty took what: the engine works the penalties out when it scores
 * (AssessRecord::getQuestionPartScores, scoreAfterPenalty). This asks it, without rendering
 * anything, for the version of each question that counts, and keeps only the late step's
 * share. Penalties apply in turn (retry, new version, late, early bonus, over time), so the
 * late penalty takes its percent of what the earlier ones left.
 *
 * Not counted: a part or question whose score a teacher set (scoreoverride), a withdrawn
 * question, a completed drill, and an all-or-nothing question scored 0. Read-only.
 *
 * Method: GET
 *   cid, aid   the course and assessment
 *   uids       optional: a comma list of user ids; default every student with a record
 *
 * Returns: { students: [ { userid, points, questions } ] }, students with no late loss left
 *   out. points: rounded to 2 places; questions: how many questions lost points to lateness.
 */

$no_session_handler = 'json_error';
require_once "../init.php";
require_once "./common_start.php";
require_once "./AssessInfo.php";
require_once "./AssessRecord.php";
require_once './AssessUtils.php';

header('Content-Type: application/json; charset=utf-8');

check_for_required('GET', array('aid', 'cid'));
$cid = Sanitize::onlyInt($_GET['cid']);
$aid = Sanitize::onlyInt($_GET['aid']);
$uids = array();
if (!empty($_GET['uids'])) {
  $uids = array_values(array_filter(array_map('intval', explode(',', $_GET['uids']))));
}

if (!$isActualTeacher) {
  echo '{"error": "no_access"}';
  exit;
}

$stm = $DBH->prepare("SELECT courseid,submitby,ver FROM imas_assessments WHERE id=:id");
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
$by_question = ($arow['submitby'] == 'by_question');

$assess_info = new AssessInfo($DBH, $aid, $cid, false);
$assess_info->loadQuestionSettings('all', false, false);

$exceptionarr = array();
$stm = $DBH->prepare("SELECT ie.* FROM imas_exceptions AS ie
  JOIN imas_students AS istu ON istu.userid=ie.userid
  WHERE istu.courseid=:courseid AND ie.assessmentid=:assessmentid AND ie.itemtype='A'");
$stm->execute(array(':courseid'=>$cid, ':assessmentid'=>$aid));
while ($line = $stm->fetch(PDO::FETCH_ASSOC)) {
  $exceptionarr[$line['userid']] = $line;
}

$qarr = array(':courseid'=>$cid, ':assessmentid'=>$aid);
$query = "SELECT iar.* FROM imas_assessment_records AS iar
  JOIN imas_students AS istu ON istu.userid=iar.userid AND istu.courseid=:courseid
  WHERE iar.assessmentid=:assessmentid";
if (count($uids) > 0) {
  $query .= " AND iar.userid IN (" . implode(',', $uids) . ")";
}
$stm = $DBH->prepare($query);
$stm->execute($qarr);

/**
 * The points the late step took from one part: each penalty in the order scoreAfterPenalty
 * applies them, from the part's raw score times its points.
 */
function nx_late_points($part) {
  if (empty($part['penalties']) || !isset($part['rawscore'])) {
    return 0;
  }
  $v = $part['rawscore'] * $part['points_possible'];
  $lost = 0;
  foreach ($part['penalties'] as $pen) {
    $pct = $pen['pct'] / 100;
    if ($pen['type'] === 'late') {
      $take = min($v, $v * $pct);
      $lost += $take;
      $v -= $take;
    } else if ($pen['type'] === 'early') {
      $v *= (1 + $pct);
    } else {
      $v = max(0, $v * (1 - $pct));
    }
  }
  return $lost;
}

$students = array();
while ($line = $stm->fetch(PDO::FETCH_ASSOC)) {
  $assess_info->setException($line['userid'], $exceptionarr[$line['userid']] ?? false, true);
  $assess_record = new AssessRecord($DBH, $assess_info, false);
  $assess_record->setRecord($line);
  $assess_record->parseData(); // getQuestionPartScores reads it, as reTotalAssess parses first
  // the record's data as AssessRecord::parseData reads it (its copy is private)
  $data = ($line['scoreddata'] != '') ? json_decode(Sanitize::gzexpand($line['scoreddata']), true) : null;
  if (empty($data['assess_versions'])) {
    continue;
  }
  if ($by_question) {
    $av = 0;
  } else {
    $av = $data['scored_version'] ?? 0;
    // only a submitted attempt counts by assessment
    if (empty($data['assess_versions'][$av]) || ($data['assess_versions'][$av]['status'] ?? 0) != 1) {
      continue;
    }
  }
  $aver = $data['assess_versions'][$av];
  if (empty($aver['questions'])) {
    continue;
  }
  $points = 0;
  $questions = 0;
  foreach ($aver['questions'] as $qn => $q) {
    if (!empty($q['withdrawn']) || !empty($q['drillcomplete'])) {
      continue;
    }
    $qv = $by_question ? ($q['scored_version'] ?? 0) : 0;
    $qver = $q['question_versions'][$qv] ?? null;
    if ($qver === null) {
      continue;
    }
    $ov = $qver['scoreoverride'] ?? null;
    if ($ov !== null && !is_array($ov)) {
      continue; // the teacher set the whole question's score
    }
    list($qScore, $qRaw, $parts, $scoredTry) =
      $assess_record->getQuestionPartScores($qn, $by_question ? $qv : $av, 'all', is_array($ov) ? $ov : array());
    if (!empty($qver['singlescore']) && $qver['singlescore'] == 'allornothing' && $qRaw < .98) {
      continue;
    }
    $lost = 0;
    foreach ($parts as $pn => $part) {
      if (is_array($ov) && isset($ov[$pn])) {
        continue; // the teacher set this part's score
      }
      $lost += nx_late_points($part);
    }
    if ($lost > 0.005) {
      $points += $lost;
      $questions++;
    }
  }
  if ($questions > 0) {
    $students[] = array(
      'userid' => intval($line['userid']),
      'points' => round($points, 2),
      'questions' => $questions
    );
  }
}

echo json_encode(array('students' => $students));
