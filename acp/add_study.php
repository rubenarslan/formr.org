<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "admin/admin_header.php";
require_once INCLUDE_ROOT . "view_header.php";
require_once INCLUDE_ROOT . "acp/acp_nav.php";
?>
<h2>Create new study and upload item table</h2>
<p>The item table has to fulfill the following criteria:</p>

<ol>
<li>The format must be one of .csv, .xls, .xlsx, .ods (OpenOffice), .xml, .txt</li>
<li>.csv-files have to use the comma as a separator, "" as escape characters and UTF-8 as the charset. Because there are inconsistencies when creating CSV files using various spreadsheet programs (e.g. German excel), you should probably steer clear of this.</li>
<li>The first line has to contain the column names you used.</li>
<li>The following column names are used. You can add others, they will be ignored.<ul>
	<li><strong>variablenname</strong> (mandatory)</li>
	<li><strong>typ</strong> (mandatory)</li>
	<li>wortlaut</li>
	<li>antwortformatanzahl</li>
	<li>ratinguntererpol</li> 
	<li>ratingobererpol</li>
	<li>choice1, choice2,	choice3,	choice4,	choice5,	choice6,	choice7,	choice8,	choice9,	choice10, choice11,	choice12,	choice13,	choice14</li>
	<li>skipif</li>
</ul>
</li>
</ol>
<form class="form-horizontal" enctype="multipart/form-data"  id="add_study" name="add_study" method="post" action="<?=WEBROOT?>admin/study_added.php">
	<div class="control-group">
		<label class="control-label" for="kurzname">
			<?php echo _("Survey shorthand name:<br>(will be used for the results table in the database (and thus skipIfs etc)"); ?>
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
			<input required type="submit" value="<?php echo _("Create survey"); ?>">
		</div>
	</div>
</form>

<?php
require_once INCLUDE_ROOT . "view_footer.php";