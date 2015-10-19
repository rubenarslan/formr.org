<?php
Template::load('header');
Template::load('acp_nav');
$resultCount = $study->getResultCount();
?>

<div class="row">
	<div class="col-lg-10 col-sm-12 col-md-10">
		<div class="transparent_well col-md-12" style="padding-bottom: 20px;">
			<h2>Upload item table</h2>
			<?php if (!empty($google['link'])): ?>
				<div class="alert alert-info">
					This survey was created from the following google sheet <a href="<?php echo $google['link']; ?>" target="_blank"><?php echo $google['link']; ?></a>
				</div>
			<?php endif; ?>
			<p>Please keep this in mind when uploading item tables:</p>
			<ul class="fa-ul fa-ul-more-padding">
				<li>
					<i class="fa-li fa fa-table"></i> The format must be one of <abbr title='Old-style Excel spreadsheets'>.xls</abbr>, <abbr title='New-style Excel spreadsheets, Office Open XML'>.xlsx</abbr>, <abbr title='OpenOffice spreadsheets / Open document format for Office Applications'>.ods</abbr>, <abbr title='extensible markup language'>.xml</abbr>, <abbr title='text files'>.txt</abbr>, or <abbr title='.csv-files (comma-separated value) have to use the comma as a separator, "" as escape characters and UTF-8 as the charset. Because there are inconsistencies when creating CSV files using various spreadsheet programs (e.g. German excel), you should probably steer clear of this.'>.csv</abbr>.
				</li>
				<li>
					<i class="fa-li fa fa-exclamation-triangle"></i> Existing results <em>should</em> be preserved if you did not remove, rename or <abbr title="that is to say you change their item type to something else, e.g. from number to select">re-type</abbr> items <i class="fa fa-meh-o" title="And this might happen to you by accident too. So be careful and back up your data."></i>.<br>
					Changes to labels and choice labels are okay (fixing typos etc.). <br>
					If you <strong>keep the confirmation box below empty</strong>, the changes will only happen, if the results can be preserved.<br>
					To possibly <strong>overwrite</strong> results by uploading a new item table, you will have to enter the study's name into the box.<br>
					<strong>Always back up your data, before doing the latter.</strong>
				</li>
				<li>
					<i class="fa-li fa fa-lock"></i> The name you chose for this survey is now locked.
					<ul class="fa-ul">
						<li>
							<i class="fa-li fa fa-check"></i> The uploaded file's name has to match <code><?= $study->name ?></code>, so you cannot accidentally upload the wrong item table.
						</li>
						<li>
							<i class="fa-li fa fa-check"></i> You can, however, put version numbers behind a dash at the end: <code><?= $study->name ?>-v2.xlsx</code>. The information after the dash and the file format are ignored.
						</li>
					</ul>
				</li>
			</ul>
			<hr />
			
			<div class="col-md-6">
				<form class="" enctype="multipart/form-data"  id="upload_items" name="upload_items" method="post" action="">
					<input type="hidden" name="study_id" value="<?= $study->id ?>">
					<div class="form-group">
						<h3><label class="control-label" for="file_upload">
								Please upload an item table <i class="fa fa-info-circle" title="Did you know, that on many computers you can also drag and drop a file on this box instead of navigating there through the file browser?"></i>: 
								<small><br />(Excel and JSON supported)</small>
							</label></h3>
						<div class="controls">
							<input required name="uploaded" type="file" id="file_upload">
						</div>
					</div>
					
					<?php
						$results = $resultCount['finished'];
						if ($results > 0): 
					?>
							<div class="form-group">
								<label class="control-label" for="delete_confirm" title="this is required to avoid accidental deletions">Do you want to delete the results, if the item table changes were too major?<br><strong>Leave this field empty</strong> if you're fixing typos in a live study.</label>
								<div class="controls">
									<div class="input-group">
										<span class="input-group-addon"><i class="fa fa-pencil-square"></i></span>
										<input class="form-control" name="delete_confirm" id="delete_confirm" type="text" placeholder="survey name (see up left)"></label>
									</div>
								</div>
							</div>
						<?php else: ?>
							<input name="delete_confirm" type="hidden" value="">
						<?php endif; ?>
						<div class="form-group">
							<div class="controls">
								<?php
								if ($results > 10):
									$btnclass = 'btn-danger';
									$icon = 'fa-bolt';
								elseif ($results > 0):
									$btnclass = 'btn-warning';
									$icon = 'fa-exclamation-triangle';
								else:
									$btnclass = 'btn-success';
									$icon = 'fa-pencil-square';
								endif;
								?>
								<button class="btn btn-default <?= $btnclass ?> btn-lg" type="submit"><i class="fa-fw fa <?= $icon ?>"></i>
									<?php
										echo $results ? __("Upload new items, possibly overwrite %d existing results.", $results) : _("Upload new items.");
									?>
								</button>
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
