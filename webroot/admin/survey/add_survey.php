<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";

if (!empty($_POST) AND !isset($_FILES['uploaded'])) 
{
	alert('<strong>Error:</strong> You have to select an item table file here.','alert-danger');
}
elseif(isset($_FILES['uploaded']))
{
	unset($_SESSION['study_id']);
	unset($_GET['study_name']);
	require_once INCLUDE_ROOT . "Model/Study.php";
	$filename = basename( $_FILES['uploaded']['name']);
	$survey_name = preg_filter("/^([a-zA-Z][a-zA-Z0-9_]{2,20})(-[a-z0-9A-Z]+)?\.[a-z]{3,4}$/","$1",$filename); // take only the first part, before the dash if present or the dot if present

	$study = new Study($fdb, null, array(
		'name' => $survey_name,
		'user_id' => $user->id
	));
	if($study->create())
	{	
		if($study->uploadItemTable($_FILES['uploaded']))
		{
			alert('<strong>Success!</strong> New survey created!','alert-success');
			redirect_to("admin/survey/{$study->name}/show_item_table");
		}
	}
}
unset($study);

require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
?>
<div class="col-md-4 col-md-offset-1 well">

<h2><i class="fa fa-pencil-square"></i> Create new survey &amp; upload item table</h2>
<p>Please keep this in mind when uploading surveys</p>

<ul class="fa-ul fa-ul-more-padding">
	<li>
		<i class="fa-li fa fa-table"></i> The format must be one of <abbr title='Old-style Excel spreadsheets'>.xls</abbr>, <abbr title='New-style Excel spreadsheets, Office Open XML'>.xlsx</abbr>, <abbr title='OpenOffice spreadsheets / Open document format for Office Applications'>.ods</abbr>, <abbr title='extensible markup language'>.xml</abbr>, <abbr title='text files'>.txt</abbr>, or <abbr title='.csv-files (comma-separated value) have to use the comma as a separator, "" as escape characters and UTF-8 as the charset. Because there are inconsistencies when creating CSV files using various spreadsheet programs (e.g. German excel), you should probably steer clear of this.'>.csv</abbr>.
	</li>

	<li>
		<i class="fa-li fa fa-exclamation-triangle"></i> The survey shorthand will be derived from the filename. 
		<ul class="fa-ul">
			<li><i class="fa-li fa fa-check"></i> If your spreadsheet was named <code>survey_1-v2.xlsx</code> it would be <code>survey_1</code>.</li> 
			<li><i class="fa-li fa fa-check"></i> The name can contain <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the underscore (at least 2, at most 20).<li>
			<li><i class="fa-li fa fa-check"></i> It needs to start with a letter.</li>
			<li><i class="fa-li fa fa-check"></i> As shown above, you can add version numbers, they will be ignored.</li>
		</ul>
	</li>
	<li>
		<i class="fa-li fa fa-unlock-alt"></i> The name you choose here cannot be changed. It will be used to refer to this survey's results in many places.<br>
		<strong>Make it meaningful.</strong>
	</li>
</ul>
<form class="" enctype="multipart/form-data"  id="add_study" name="add_study" method="post" action="<?=WEBROOT?>admin/survey/">
	<input type="hidden" name="new_study" value="1">
	<div class="form-group">
		<h3><label class="control-label" for="file_upload">
				Please choose an item table <i class="fa fa-info-circle" title="Did you know, that on many computers you can also drag and drop a file on this box instead of navigating there through the file browser?"></i>: 
		</label></h3>
		<div class="controls">
			<input required name="uploaded" type="file" id="file_upload">
		</div>
	</div>
	<div class="form-group">
		<div class="controls">
			<button class="btn btn-default btn-success btn-lg" type="submit"><i class="fa-fw fa fa-pencil-square"></i> Create survey</button>
		</div>
	</div>
</form>
</div>

<?php
require_once INCLUDE_ROOT . "View/footer.php";