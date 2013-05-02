<?php
require_once '../includes/define_root.php';
require_once INCLUDE_ROOT . "config/config.php";
if(!userIsAdmin()) {
  header("Location: ".WEBROOT."index.php");
  die();
}

require_once INCLUDE_ROOT . "view_header.php";
?>	
<ul class="nav nav-tabs">
    <li class="active"><a href="<?=WEBROOT?>acp/acp.php"><?php echo _("Admin control panel"); ?></a></li>   
	
	<li>
		<a href="<?=WEBROOT?>acp/add_study.php"><?php echo _("Studie anlegen"); ?></a>
	</li>
	<li>
		<a href="<?=WEBROOT?>acp/add_run.php"><?php echo _("Studien Run erstellen"); ?></a>
	</li>
	<li>
		<a href="<?=WEBROOT?>index.php"><?php echo _("Zum öffentlichen Bereich"); ?></a>
	</li>

	<li><a href="<?=WEBROOT?>logout.php"><?php echo _("Ausloggen"); ?></a></li>
	<li><a href="<?=WEBROOT?>edit_user.php"><?php echo _("Einstellungen ändern"); ?></a></li>
</ul>

<?php
$studies = $currentUser->GetStudies();
if($studies) {
  echo '<ul class="nav nav-pills nav-stacked">';
  foreach($studies as $study) {
    echo "<li>
		<a href='".WEBROOT."acp/view_study.php?id=".$study->id."'>".$study->name."</a>
	</li>";
  }
  echo "</ul>";
}
?>
<?php
$runs=$currentUser->GetRuns();
if($runs) {
	echo '<ul class="nav nav-pills nav-stacked">';
	foreach($runs as $run) {
		echo "<li>
			<a href='".WEBROOT."acp/view_run.php?id=".$run->id."'>".$run->name."
		</li>";
	}
  echo "</ul>";
}
?>
	
<?php
require_once INCLUDE_ROOT . "view_footer.php";