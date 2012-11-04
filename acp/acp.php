<?php
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
    echo "<li><p><a href='view_study.php?id=".$study->id."'>".$study->name."</a></p></li>";
  }
  echo "</ul>";
}
?>
</p>
<p>
<a href="add_study.php">Studie anlegen</a>
</p>
<p>
<?php
$runs=$currentUser->GetRuns();
if($runs) {
  echo "<ul>";
  foreach($runs as $run) {
    echo "<li><p><a href='view_run.php?id=".$run->id."'>".$run->name."</a></p></li>";
  }
  echo "</ul>";
}
?>
</p>
<p>
<a href="add_run.php">Studien Run erstellen</a>
</p>
	
<?php
include("post_content.php");
?>	