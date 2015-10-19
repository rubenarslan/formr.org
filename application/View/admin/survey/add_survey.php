<?php
Template::load('header');
Template::load('acp_nav');
?>
</div>
<div class="survey_header">&nbsp;
</div>	
<div class="col-lg-10 col-md-10 col-sm-9 main_body">

	<div class="row">
		<div class="col-md-12 col-lg-offset-1 transparent_well">

			<h2><i class="fa fa-pencil-square"></i> Create new survey &amp; upload item table</h2>
			<p>Please keep this in mind when uploading surveys</p>

			<ul class="fa-ul fa-ul-more-padding">
				<li>
					<i class="fa-li fa fa-table"></i> The format must be one of <abbr title='Old-style Excel spreadsheets'>.xls</abbr>, <abbr title='New-style Excel spreadsheets, Office Open XML'>.xlsx</abbr>, <abbr title='OpenOffice spreadsheets / Open document format for Office Applications'>.ods</abbr>, <abbr title='extensible markup language'>.xml</abbr>, <abbr title='text files'>.txt</abbr>, or <abbr title='.csv-files (comma-separated value) have to use the comma as a separator, "" as escape characters and UTF-8 as the charset. Because there are inconsistencies when creating CSV files using various spreadsheet programs (e.g. German excel), you should probably steer clear of this.'>.csv</abbr>.
				</li>

				<li>
					<i class="fa-li fa fa-exclamation-triangle"></i> The survey shorthand will be derived from the filename. 
					<ul class="fa-ul">
						<li><i class="fa-li fa fa-check"></i> If your spreadsheet was named <code>survey_1-v2.xlsx</code> the name would be <code>survey_1</code>.</li> 
						<li><i class="fa-li fa fa-check"></i> The name can contain <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the underscore. The name has to at least 2, at most 64 characters long. You can't use spaces, periods or dashes in the name.<li>
						<li><i class="fa-li fa fa-check"></i> It needs to start with a letter.</li>
						<li><i class="fa-li fa fa-check"></i> As shown above, you can add version numbers (or anything) after a dash, they will be ignored.</li>
					</ul>
				</li>
				<li>
					<i class="fa-li fa fa-unlock-alt"></i> The name you choose here cannot be changed. It will be used to refer to this survey's results in many places.<br>
					<strong>Make it meaningful.</strong>
				</li>
			</ul>
			<hr />
			<div class="col-md-6">
				<form class="" enctype="multipart/form-data"  id="add_study" name="add_study" method="post" action="<?php echo admin_url('survey/add_survey'); ?>">
					<input type="hidden" name="new_study" value="1">
					<div class="form-group">
						<h3><label class="control-label" for="file_upload">
								Please upload an item table <i class="fa fa-info-circle" title="Did you know, that on many computers you can also drag and drop a file on this box instead of navigating there through the file browser?"></i>: 
								<small><br />(Excel and JSON supported)</small>
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
			<div class="col-md-6" style="border-left: 1px solid #efefef; padding-left: 35px;">
				<h3><label class="control-label" for="file_upload">
						Import a google sheet <i class="fa fa-info-circle" title="You can also create an item table from a google sheet"></i>: 
					</label></h3>
				<h3>&nbsp;</h3>
				<a href="#" data-toggle="modal" data-target="#google-import" class="btn btn-default btn-lg"><i class="fa fa-download"></i>Import Google Sheet</a>
			</div>
		</div>
	</div>


	<div class="row">
		<div class="col-md-8 col-lg-offset-1">
			<h2><i class="fa fa-question-circle"></i> Help</h2>
			<ul class="nav nav-tabs">
				<li><a href="#sample_survey_sheet" data-toggle="tab">Survey spreadsheet</a></li>
				<li><a href="#sample_choices_sheet" data-toggle="tab">Choices spreadsheet</a></li>
				<li class="active"><a href="#available_items" data-toggle="tab">Item types</a></li>
			</ul>

			<div class="tab-content">
				<div class="tab-pane fade" id="sample_survey_sheet">
					<?php Template::load('public/documentation/sample_survey_sheet'); ?>
				</div>
				<div class="tab-pane fade" id="sample_choices_sheet">
					<?php Template::load('public/documentation/sample_choices_sheet'); ?>
				</div>
				<div class="tab-pane fade in active" id="available_items">
					<?php Template::load('public/documentation/item_types'); ?>
				</div>
			</div>
		</div>
	</div>

	<?php
	
	Template::load('admin/survey/goole_sheet_import', array('params' => $google));
	Template::load('footer');
	