<?php
require_once "config/config.php";

global $currentUser;
?>

<?php
include("pre_content.php");
?>

<?php

$studies=$currentUser->GetAvailableStudies();
$runs=$currentUser->GetAvailableRuns();
if($studies or $runs) {
  echo "<p>Aktuelle Studien:</p>";
  echo "<ul>";
}
if($runs) {
  foreach($runs as $run) {
    if($currentUser->anonymous and $run->registered_req)
      break;
    echo "<li><p><a href='pre_survey.php?run_id=".$run->id."'>".$run->name."</a></p></li>";
  }
}
if($studies) {
  foreach($studies as $study) {
    if($currentUser->anonymous and $study->registered_req)
      break;
    echo "<li><p><a href='pre_survey.php?study_id=".$study->id."'>".$study->name."</a></p></li>";
  }
}
if($studies or $runs)
  echo "</ul>";
?>	

<?php
include("post_content.php");
?>