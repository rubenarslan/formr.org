<?php
require_once '../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
?>
<h2>Create new study and upload item table</h2>
<p>The item table has to fulfill the following criteria:</p>

<ol>
<li>The format must be one of .csv, .xls, .xlsx, .ods (OpenOffice), .xml, .txt</li>
<li>.csv-files have to use the comma as a separator, " as escape characters and UTF-8 as the charset. Because there are inconsistencies when creating CSV files using various spreadsheet programs (e.g. German Excel), you should probably steer clear of this format.</li>
</ol>
<form class="form-horizontal" enctype="multipart/form-data"  id="add_study" name="add_study" method="post" action="<?=WEBROOT?>admin/survey/study_added.php">
	<div class="control-group">
		<label class="control-label" for="kurzname">
			<?php echo _("Survey shorthand:<br>(will be used for referring to this survey's results in many places, make it meaningful)"); ?>
		</label>
		<div class="controls">
			<input required type="text" placeholder="Name (a-Z0-9_)" name="study_name" id="kurzname">
		</div>
	</div>
	<div class="control-group">
		<label class="control-label" for="file_upload">
				<?php echo _("Please choose an item table:"); ?>
		</label>
		<div class="controls">
			<input name="uploaded" type="file" id="file_upload">
		</div>
	</div>
	<div class="control-group">
		<div class="controls">
			<input class="btn btn-success btn-large" type="submit" value="<?php echo _("Create survey"); ?>">
		</div>
	</div>
</form>

<?php
require_once INCLUDE_ROOT . "View/footer.php";