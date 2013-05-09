<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "admin/admin_header.php";
require_once INCLUDE_ROOT . "view_header.php";
require_once INCLUDE_ROOT . "acp/acp_nav.php";
?>

<form class="form-horizontal" enctype="multipart/form-data"  id="add_study" name="add_study" method="post" action="<?=WEBROOT?>admin/study_added.php">
	<div class="control-group">
		<label class="control-label" for="kurzname">
			<?php echo _("Studien Kurzname<br>(wird für URL und Ergebnistabelle in der Datenbank benutzt):"); ?>
		</label>
		<div class="controls">
			<input required type="text" placeholder="Name (a-Z0-9_)" name="study_name" id="kurzname">
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