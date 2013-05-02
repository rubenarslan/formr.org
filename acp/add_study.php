<?php
require_once '../includes/define_root.php';
require_once INCLUDE_ROOT . "config/config.php";

if(!userIsAdmin()) {
  header("Location: index.php");
  die();
}

require_once INCLUDE_ROOT . "view_header.php";
?>
<ul class="nav nav-tabs">
    <li><a href="<?=WEBROOT?>acp/acp.php"><?php echo _("Admin control panel"); ?></a></li>   
	
	<li class="active">
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
<form class="form-horizontal" enctype="multipart/form-data"  id="add_study" name="add_study" method="post" action="../admin/study_added.php">
	<div class="control-group">
		<label class="control-label" for="kurzname">
			<?php echo _("Studien Kurzname<br>(wird für URL und Ergebnistabelle in der Datenbank benutzt):"); ?>
		</label>
		<div class="controls">
			<input required type="text" placeholder="Name (a-Z0-9)" name="name" id="kurzname"  value="<?php if(isset($_POST['name'])) echo $_POST['name']; ?>"/>
		</div>
	</div>
	<div class="control-group">
		<label class="control-label" for="file_upload">
				<?php echo _("Bitte Itemtabelle auswählen:"); ?>
		</label>
		<div class="controls">
			<input name="uploaded" type="file" id="file_upload">
		</div>
	</div>
	<div class="control-group">
		<div class="controls">
			<input required type="submit" value="<?php echo _("Studie anlegen"); ?>">
		</div>
	</div>
</form>

<?php
require_once INCLUDE_ROOT . "view_footer.php";