<?php
Template::load('admin/header');
$resultCount = $study->getResultCount();
?>

<div class="content-wrapper">

	<section class="content-header">
		<h1><?= $study->name ?> <small>Survey ID: <?= $study->id ?></small></h1>
	</section>

	<section class="content">
		<div class="row">
			<div class="col-md-2">
				<?php Template::load('admin/survey/menu'); ?>
			</div>

			<div class="col-md-8">
				<div class="box box-primary">
					<div class="box-header with-border">
						<h3 class="box-title">Import Survey Items</h3>
					</div>
					<form role="form" class="" enctype="multipart/form-data"  id="upload_items" name="upload_items" method="post" action="">
						<input type="hidden" name="study_id" value="<?= $study->id ?>">
						<div class="box-body">
							<?php Template::load('public/alerts'); ?>
							
							<div class="callout callout-info">
								<h4>Please keep this in mind when uploading surveys!</h4>
								<ul class="fa-ul fa-ul-more-padding">
									<li>
										<i class="fa-li fa fa-table"></i> The format must be one of <abbr title="" data-original-title="Old-style Excel spreadsheets">.xls</abbr>, <abbr title="" data-original-title="New-style Excel spreadsheets, Office Open XML">.xlsx</abbr>, <abbr title="" data-original-title="OpenOffice spreadsheets / Open document format for Office Applications">.ods</abbr>, <abbr title="" data-original-title="extensible markup language">.xml</abbr>, <abbr title="" data-original-title="text files">.txt</abbr>, or <abbr title="" data-original-title=".csv-files (comma-separated value) have to use the comma as a separator, &quot;&quot; as escape characters and UTF-8 as the charset. Because there are inconsistencies when creating CSV files using various spreadsheet programs (e.g. German excel), you should probably steer clear of this.">.csv</abbr>.
									</li>
									<li>
										<i class="fa-li fa fa-exclamation-triangle"></i> Existing results <em>should</em> be preserved if you did not remove, rename or <abbr title="" data-original-title="that is to say you change their item type to something else, e.g. from number to select">re-type</abbr> items <i class="fa fa-meh-o" title="" data-original-title="And this might happen to you by accident too. So be careful and back up your data."></i>.<br>
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
							</div>

							<h4>Upload an item table</h4>
							<div class="form-group">
								<input name="uploaded" type="file" id="file_upload">
								<small class="help-block"><i class="fa fa-info-circle"></i> Did you know, that on many computers you can also drag and drop a file on this box instead of navigating there through the file browser?</small>
							</div>

							<h4>
								Or use 	<?php if (!empty($google['id'])): ?>
									<a href="<?php echo $google['link']; ?>" target="_blank" title="Go to your Google sheet, check if this is the right one">this</a>
								<?php else: ?>
									a
								<?php endif; ?> Googlesheet
							</h4>
							<div class="form-group">
								<label>Sheet link</label>
								<textarea name="google_sheet" class="form-control" rows="3" placeholder="Enter Googlesheet share link"><?php if (!empty($google['id'])) echo h($google['link']); ?></textarea>
								<small class="help-block"><i class="fa fa-info-circle"></i> Make sure this sheet is accessible by anyone with the link</small>
							</div>

							<?php if ($resultCount['real_users'] > 0): ?>
								<div class="form-group alert-danger col-md-12">
									<h4><i class="fa fa-exclamation-triangle"></i> Delete Results Confirmation</h4>
									<label class="control-label" for="delete_confirm" title="" data-original-title="this is required to avoid accidental deletions">Do you want to delete the results, if the item table changes were too major?<br>
										<strong>Enter the survey name below</strong> if you're okay with data being <abbr title="" data-original-title="e.g. when you removed an item, see above">potentially</abbr> deleted.<br>
										<strong>Leave this field empty</strong> if you're fixing typos in a <abbr title="" data-original-title="upload will fail if deletion is required">live study.</abbr></label>
									<div class="controls">
										<div class="input-group">
											<span class="input-group-addon"><i class="fa fa-pencil-square"></i></span>
											<input class="form-control" name="delete_confirm" id="delete_confirm" type="text" placeholder="survey name (see up left)">
										</div>
									</div>
									<br>
								</div>
							<?php else: ?>
								<input name="delete_confirm" type="hidden" value="">
							<?php endif; ?>
						</div>
						<!-- /.box-body -->

						<div class="box-footer">
							<?php
							if ($resultCount['real_users'] > 10) {
								$btnclass = 'btn-danger';
								$icon = 'fa-bolt';
							} elseif ($resultCount['real_users'] > 0) {
								$btnclass = 'btn-warning';
								$icon = 'fa-exclamation-triangle';
							} else {
								$btnclass = 'btn-primary';
								$icon = 'fa-pencil-square';
							};
							?>
							<button class="btn btn-default <?= $btnclass ?> " type="submit"><i class="fa-fw fa <?= $icon ?>"></i>
								<?php
								echo (array_sum($resultCount)) ? "Upload new items, possibly partially delete {$resultCount['real_users']} real results and {$resultCount['testers']} test sessions." : "Upload new items";
								?>
							</button>
						</div>
					</form>
				</div>
				<p>&nbsp;</p>
				<a href="<?= site_url('documentation/#sample_survey_sheet') ?>" target="_blank"><i class="fa fa-question-circle"></i> more help on creating survey sheets</a>

			</div>
			<div class="clear clearfix"></div>
		</div>

	</section>
	<!-- /.content -->
</div>
<!-- /.content-wrapper -->

<?php Template::load('admin/footer'); ?>