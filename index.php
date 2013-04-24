<?php
require_once "includes/define_root.php";

require_once INCLUDE_ROOT."config/config.php";

require_once INCLUDE_ROOT."view_header.php";

$studies = $currentUser->GetAvailableStudies();
$runs = $currentUser->GetAvailableRuns();

echo '<ul class="nav nav-tabs">';

if(userIsAdmin())
{
   ?>
    <li><a href="<?=WEBROOT?>acp/acp.php"><?php echo _("Admin control panel"); ?></a></li>   
   <?php
}

if(userIsLoggedIn()) {  
	?>
	<li><a href="<?=WEBROOT?>logout.php"><?php echo _("Ausloggen"); ?></a></li>
	<li><a href="<?=WEBROOT?>edit_user.php"><?php echo _("Einstellungen ändern"); ?></a></li>
<?php
} else {
?>
     <li><a href="<?=WEBROOT?>login.php"><?php echo _("Login"); ?></a></li>
     <li><a href="<?=WEBROOT?>register.php"><?php echo _("Registrieren") ?></a></li>
<?php
}

echo "</ul>";

if($studies or $runs) {
  echo "<h3>Aktuelle Studien:</h3>";
  echo "<ul class='span4 nav nav-pills nav-stacked'>";
}
if($runs) {
  foreach($runs as $run) {
    if($currentUser->anonymous and $run->registered_req)
      break;
    echo "<li>
		<a href='".WEBROOT."survey.php?run_id=".$run->id."'>".$run->name."</a>
	</li>";
  }
}
if($studies) {
  foreach($studies as $study) {
    if($currentUser->anonymous and $study->registered_req)
      break;
    echo "<li>
		<a href='".WEBROOT."survey.php?study_id=".$study->id."'>".$study->name."</a>
	</li>";
  }
}
if($studies or $runs)
  echo "</ul>";

require_once INCLUDE_ROOT."view_footer.php";
