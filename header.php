<?php
require_once "config/config.php";
global $currentUser;

$sid=0;
$rid=0;
if(isset($_GET['study_id'])) {
  $sid=$_GET['study_id'];
  $_SESSION['study_id']=$sid;
} /* else if(isset($_SESSION['study_id'])) */
  /* $sid=$_SESSION['study_id']; */

if(isset($_GET['run_id'])) {
  $rid=$_GET['run_id'];
  $_SESSION['run_id']=$rid;
} /* else if(isset($_SESSION['run_id'])) */
  /* $rid=$_SESSION['run_id']; */

if($sid==0 and $rid==0)
  header("Location: index.php");

if($rid!=0) {
  $run=new Run;
  $run->fillIn($rid);
  if(!$run->status or !$currentUser->EligibleForRun($run))
    header("Location: index.php");  
}

if($sid==0) {
  if(!isset($run))
    header("Location: index.php");
  $sid=$run->GetFirstStudyId();
  if(!$run->status or $sid==-1)
    header("Location: index.php");
}
$study=new Study;
$study->fillIn($sid);
if(!$study->status)
  header("Location: index.php");
if(!isset($run)) {
  if(!$currentUser->EligibleForStudy($study))
    header("Location: index.php");
} else {
  /* $tmp=$currentUser->EligibleForStudyRun($study,$run); */
  /* if($tmp!==true) { */
  /*   echo sizeof($tmp); */
  /* die(); */
  /* } */
  if(!$currentUser->EligibleForStudyRun($study,$run))
    header("Location: index.php");
}
  define('TABLEPREFIX',$study->prefix."_");    

function studyDone() {
  global $study;
  global $run;
  global $currentUser;

  if(!$currentUser->userCompletedStudy($study))
    header("Location: index.php");
  if(!isset($study))
    header("Location: index.php");
  if(!isset($run)) {
    header("Location: study_done.php?study_id=$study->id");
  } else {
    header("Location: study_done.php?study_id=$study->id&run_id=$run->id");
  }  
}

?>
