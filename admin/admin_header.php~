<?php
require_once "../config/config.php";
global $currentUser;
if(!userIsAdmin()) {
  header("Location: ../index.php");
  die();
}
$id=0;
if(isset($_GET['study_id'])) {
  $id=$_GET['study_id'];
  $_SESSION['study_id']=$id;
} else if(isset($_SESSION['study_id']))
  $id=$_SESSION['study_id'];
else 
  header("Location: ../index.php");

$study=new Study;
$study->fillIn($id);
if(!$study->status)
  header("Location: ../index.php");
if(!$currentUser->ownsStudy($id))
  header("Location: ../index.php");
define('TABLEPREFIX',$study->prefix."_");    

?>
