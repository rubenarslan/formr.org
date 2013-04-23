<?php
require_once "Model/Study.php";
global $currentUser;
global $study;
global $run;

if(!$currentUser->userStartedStudy($study))
  header("Location: index.php");

if(isset($run) and is_object($run)) 
  header("Location: survey.php?study_id=$study->id&run_id=$run->id");
else if(isset($study) and is_object($study))
  header("Location: survey.php?study_id=$study->id");
else 
  header("Location: index.php");

include("pre_content.php");
?>

<p>PSYTEST</p>
<?php

errorOutput($errors);


?>	

<?php
include("post_content.php");
