<?php
require_once '../includes/define_root.php';
require_once INCLUDE_ROOT . "config/config.php";
if(!userIsAdmin()) {
  header("Location: ../index.php?msg=notadmin");
  exit;
}
if(!isset($study))
{
	$id=0;
	if(isset($_GET['study_id'])) {
	  $id = $_GET['study_id'];
	  $_SESSION['study_id'] = $id;
	} else if(isset($_SESSION['study_id']))
	  $id = $_SESSION['study_id'];
	else 
	  header("Location: ../index.php");

	$study=new Study;
	$study->fillIn($id);
	if(!defined("TABLEPREFIX")) define('TABLEPREFIX',$study->prefix."_");    
}
else
{
	$id = $study->id;
}

if(!$study->status)
{
	header("Location: ../index.php?msg=studybroken".$study->status);
	exit;
}
elseif(!$currentUser->ownsStudy($id))
{
	header("Location: ../index.php?msg=dontownstudy");
	exit;
}
