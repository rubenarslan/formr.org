<?php
require_once '../includes/define_root.php';
require_once INCLUDE_ROOT . "config/config.php";
require_once INCLUDE_ROOT . "Model/StudyX.php";
if(!userIsAdmin()) {
  header("Location: ../index.php?msg=notadmin");
  exit;
}
$study = new StudyX($_GET['study_name']);

if(!$study->valid)
{
	header("Location: ../index.php?msg=studybroken");
	exit;
}
elseif(!$currentUser->ownsStudy($study->id))
{
	header("Location: ../index.php?msg=dontownstudy");
	exit;
}
