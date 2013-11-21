<?
require_once '../../../define_root.php';
require_once INCLUDE_ROOT.'View/admin_header.php';

if (!empty($_POST) AND !isset($_FILES['uploaded'])) 
{
	alert('<strong>Error:</strong> You have to select an item table file here.','alert-danger');
}
elseif(isset($_FILES['uploaded']))
{
	$filename = basename( $_FILES['uploaded']['name']);
	$survey_name = preg_filter("/^([a-zA-Z][a-zA-Z0-9_]{2,20})(-[a-z0-9A-Z]+)?\.[a-z]{3,4}$/","$1",$filename); // take only the first part, before the dash if present or the dot if present
	
	if($study->name !== $survey_name)
	{
		alert('<strong>Error:</strong> The uploaded file name <code>'.htmlspecialchars($survey_name).'</code> did not match the study name <code>'.$study->name.'</code>.','alert-danger');
	}
	else
	{
		if($study->uploadItemTable($_FILES['uploaded']))
		{
			redirect_to("admin/survey/{$study->name}/show_item_table");
		}
	}
}

require_once INCLUDE_ROOT.'View/header.php';
require_once INCLUDE_ROOT.'View/acp_nav.php';
?>

<div class="row">
	<div class="col-lg-7 col-md-8 well">

		<p>Please keep this in mind when uploading item tables:</p>
		<ul class="fa-ul fa-ul-more-padding">
			<li>
				<i class="fa-li fa fa-table"></i> The format must be one of <abbr title='Old-style Excel spreadsheets'>.xls</abbr>, <abbr title='New-style Excel spreadsheets, Office Open XML'>.xlsx</abbr>, <abbr title='OpenOffice spreadsheets / Open document format for Office Applications'>.ods</abbr>, <abbr title='extensible markup language'>.xml</abbr>, <abbr title='text files'>.txt</abbr>, or <abbr title='.csv-files (comma-separated value) have to use the comma as a separator, "" as escape characters and UTF-8 as the charset. Because there are inconsistencies when creating CSV files using various spreadsheet programs (e.g. German excel), you should probably steer clear of this.'>.csv</abbr>.
			</li>
			<li>
				<i class="fa-li fa fa-exclamation-triangle"></i> Existing results <em>should</em> be preserved if you did not add, reorder, rename, <abbr title="that is to say you change their item type to something else, e.g. from number to select">re-type</abbr> or remove items <i class="fa fa-meh-o" title="The boring technical way to say this: if the new item table leads to the same results table structure as the old one."></i>.<br>
				Changes to labels and choice labels should be okay. <br>
				<strong>Don't rely on it</strong>, always back up the data.</li>
				<li>
					<i class="fa-li fa fa-lock"></i> The name you chose for this survey is now locked.
					<ul class="fa-ul">
						<li>
							<i class="fa-li fa fa-check"></i> The uploaded file's name has to match <code><?=$study->name?></code>, so you cannot accidentally upload the wrong item table.
						</li>
						<li>
							<i class="fa-li fa fa-check"></i> You can, however, put version numbers behind a dash at the end: <code><?=$study->name?>-v2.xlsx</code>. The information after the dash and the file format are ignored.
						</li>
					</ul>
				</li>
		</ul>

		<form class="" enctype="multipart/form-data"  id="upload_items" name="upload_items" method="post" action="<?=WEBROOT?>admin/survey/<?=$study->name?>/upload_items">
					<input type="hidden" name="study_id" value="<?=$study->id?>">

			<div class="form-group">
				<h3>
					<label class="control-label" for="file_upload">
					Please choose an item table <i class="fa fa-info-circle" title="Did you know, that on many computers you can also drag and drop a file on this box instead of navigating there through the file browser?"></i>: 
					</label>
				</h3>
				<div class="controls">
					<input name="uploaded" type="file" id="file_upload">
				</div>
			</div>
			<div class="form-group">
				<div class="controls">
					<?php
						$res = ($resultCount['begun']+$resultCount['finished']);
						if($res>10): 
							$btnclass = 'btn-danger';
							$icon = 'fa-bolt';
						elseif($res>0):
							$btnclass = 'btn-warning';
							$icon = 'fa-exclamation-triangle';
						else:
							$btnclass = 'btn-success';
							$icon = 'fa-pencil-square';
						endif;
					?>
						<button class="btn btn-default <?=$btnclass?> btn-lg" type="submit"><i class="fa-fw fa <?=$icon?>"></i> <?php echo __("Upload new items, possibly overwrite %d existing results.", $res); ?></button>
				
				</div>
			</div>
		</form>
	</div>
</div>

<div class="row">
	<div class="col-lg-7 col-md-8">
		<h2><i class="fa fa-question-circle"></i> Help</h2>
		<ul class="nav nav-tabs">
		  <li><a href="#sample_survey_sheet" data-toggle="tab">Survey spreadsheet</a></li>
		  <li><a href="#sample_choices_sheet" data-toggle="tab">Choices spreadsheet</a></li>
		  <li class="active"><a href="#available_items" data-toggle="tab">Item types</a></li>
		</ul>
	
		<div class="tab-content">
			<div class="tab-pane fade" id="sample_survey_sheet">
				<?php
				require INCLUDE_ROOT.'View/sample_survey_sheet.php';	
				?>
			</div>
			<div class="tab-pane fade" id="sample_choices_sheet">
				<?php
				require INCLUDE_ROOT.'View/sample_choices_sheet.php';	
				?>
			</div>
			<div class="tab-pane fade in active" id="available_items">
				<?php
				require INCLUDE_ROOT.'View/item_types.php';	
				?>

			</div>
		</div>
	</div>
</div>


<?

require_once INCLUDE_ROOT.'View/footer.php';