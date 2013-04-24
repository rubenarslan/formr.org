<?php
require_once '../includes/define_root.php';
require_once INCLUDE_ROOT . "config/config.php";

global $currentUser;
if(!userIsAdmin()) {
  header("Location: ../index.php");
  die();
}

if(!empty($_POST)) {
  $errors=array();
  $run=new Run;
  $run->Constructor($_POST['name'],$currentUser->id);
  if(!$run->status) {
    $errors=$run->GetErrors();
  } else {
    if(!$run->Register())
      $errors=$run->GetErrors();
    else
      header("Location: view_run.php?id=".$run->id."");
  }

}

require_once INCLUDE_ROOT . "view_header.php";
?>
<ul class="nav nav-tabs">
    <li><a href="<?=WEBROOT?>acp/acp.php"><?php echo _("Admin control panel"); ?></a></li>   
	
	<li>
		<a href="<?=WEBROOT?>acp/add_study.php"><?php echo _("Studie anlegen"); ?></a>
	</li>
	<li class="active">
		<a href="<?=WEBROOT?>acp/add_run.php"><?php echo _("Studien Run erstellen"); ?></a>
	</li>
	<li>
		<a href="<?=WEBROOT?>index.php"><?php echo _("Zum öffentlichen Bereich"); ?></a>
	</li>

	<li><a href="<?=WEBROOT?>logout.php"><?php echo _("Ausloggen"); ?></a></li>
	<li><a href="<?=WEBROOT?>edit_user.php"><?php echo _("Einstellungen ändern"); ?></a></li>
</ul>
<?php
if(!empty($_POST) and count($errors)>0) {
?>
<div id="errors">
<?php errorOutput($errors); ?>
</div>
<?php
    }
?>
<form id="add_run" name="add_run" method="post" action="add_run.php">
  <p>
  <p>
  <label><?php echo _("Run Name"); ?>
  </label>
  <input type="text" name="name" id="name"  value="<?php if(isset($_POST['name'])) echo $_POST['name']; ?>"/>
  </p>
  <p>
  <button type="submit"><?php echo _("Run erstellen"); ?></button>
  </p>
  </form>

<?php
require_once INCLUDE_ROOT . "view_footer.php";