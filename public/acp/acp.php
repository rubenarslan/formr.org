<?php
/* require_once $_SERVER['DOCUMENT_ROOT']."/tmp/config/config.php"; */
require_once "../config/config.php";
if(!userIsAdmin()) {
  header("Location: index.php");
  die();
}
?>
<?php
include("pre_content.php");
?>		
<p>

<?php

$studies=$currentUser->GetStudies();
if($studies) {
  echo "<ul>";
  foreach($studies as $study) {
    echo "<li><p><a href='view_study.php?id=".$study->id."'>".$study->prefix."</a></p></li>";
  }
  echo "</ul>";
}


?>


</p>
<p>
<a href="add_study.php">Studie anlegen</a>
</p>
	
<?php
include("post_content.php");
?>	