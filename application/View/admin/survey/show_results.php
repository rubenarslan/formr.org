<?php Template::load('admin/header'); ?>

<div class="content-wrapper">

	<section class="content-header">
		<h1><?= $study->name ?> <small>Survey ID: <?= $study->id ?></small></h1>
	</section>

	<section class="content">
		<div class="row">
			<div class="col-md-2">
				<?php Template::load('admin/survey/menu'); ?>
			</div>

			<div class="col-md-10">
				<div class="box box-primary">
					<div class="box-header with-border">
						<h3 class="box-title">Survey Results <small> <?= (int) $resultCount['finished'] ?> complete, <?= (int) $resultCount['begun'] ?> begun, <?= (int) $resultCount['testers'] ?> testers </small></h3>
						<div class="pull-right">
							<?php if (!$study->settings['hide_results']): ?>
							<a href="#" data-toggle="modal" data-target="#download-items" class="btn btn-primary"><i class="fa fa-save"></i> Export</a>
							<a href="<?php echo admin_study_url($study->name, 'show_itemdisplay'); ?>" class="btn btn-primary"><i class="fa fa-file-text-o"></i> Detailed Results</a>
							<?php endif; ?>
						</div>
					</div>

					<?php if ($study->settings['hide_results']): ?>
					<div class="col-md-12">
						<p>&nbsp;</p>
						<p class="callout callout-warning"><i class="fa fa-warning"></i> Displaying results has been disabled for this survey.</p>
					</div>
					<div class="clearfix"></div>
					<?php endif; ?>

					<div class="box-body table-responsive no-padding">
						<?php Template::load('public/alerts'); ?>
						<div class="col-md-12" style="margin: 10px;">
							<form action="<?= admin_study_url($study_name, 'show_results') ?>" accept-charset="utf-8" method="get" class="col-md-6">
								<div class="input-group input-group-sm">
									<span class="input-group-addon">Search by session <i class="fa fa-user"></i></span>
									<input type="text" name="session" class="form-control" value="<?= h($session) ?>">
									<span class="input-group-addon">Filter Results <i class="fa fa-filter"></i></span>
									<select class="form-control" name="rfilter">
										<?php foreach ($results_filter as $f => $filter): $selected = $f == $rfilter ? 'selected="selected"' : null ?>
											<option value="<?= $f ?>" <?= $selected ?>><?= $filter['title'] ?></option>
										<?php endforeach; ?>
									</select>
									<span class="input-group-btn">
										<button type="submit" class="btn btn-default btn-flat"><i class="fa fa-search"></i></button>
									</span>
								</div>
							</form>
						</div>
						
						<table class="table table-hover">
							<?php
								$print_header = true;
								foreach($results as $row) {
									unset($row['study_id']);
									if ($print_header) {
										echo '<thead><tr>';
										foreach ($row as $field => $value) {
											echo '<th>' . $field . '</th>';
										}
										echo '</tr></thead>';
										echo '<tbody>';
										$print_header = false;
									}

									if(isset($row['created'])):
										$row['created'] = '<abbr title="'.$row['created'].'">'.timetostr(strtotime($row['created'])).'</abbr>';
										$row['ended'] = '<abbr title="'.$row['ended'].'">'.timetostr(strtotime($row['ended'])).'</abbr>';
										$row['modified'] = '<abbr title="'.$row['modified'].'">'.timetostr(strtotime($row['modified'])).'</abbr>';
										$row['expired'] = '<abbr title="'.$row['expired'].'">'.timetostr(strtotime($row['expired'])).'</abbr>';
									endif;
									echo '<tr>';
										foreach($row as $cell) {
											echo '<td>' . $cell . '</td>';
										}
									echo '</tr>';
								}
								echo '</tbody>';
							?>
						</table>
						<div class="col-md-12 pagination text-center">
							<?php $pagination->render("admin/survey/{$study_name}/show_results"); ?>
						</div>
					</div>
				</div>

			</div>
			<div class="clear clearfix"></div>
		</div>

	</section>
	<!-- /.content -->
</div>
<!-- /.content-wrapper -->

<!--- MODALS -->

<!-- Download items table modal -->
<div class="modal fade" id="download-items" tabindex="-1" role="dialog" aria-hidden="true" style="display: none;">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">×</span></button>
				<h4 class="modal-title">Export as..</h4>
			</div>
			<div class="modal-body">
				<div class="list-group col-md-11">
					<h5>Export as</h5>
					<div class="list-group-item">
						<h4 class="list-group-item-heading"><a href="<?php echo admin_study_url($study->name, 'export_results?format=csv'); ?>"><i class="fa fa-floppy-o fa-fw"></i> CSV</a></h4>
						<p class="list-group-item-text">good for big files, problematic to import into German Excel (comma-separated)</p>
					</div>

					<div class="list-group-item">
						<h4 class="list-group-item-heading"><a href="<?php echo admin_study_url($study->name, 'export_results?format=csv_german'); ?>"><i class="fa fa-floppy-o fa-fw"></i> German CSV</a></h4>
						<p class="list-group-item-text">good for German Excel (semicolon-separated)</p>
					</div>

					<div class="list-group-item">
						<h4 class="list-group-item-heading"><a href="<?php echo admin_study_url($study->name, 'export_results?format=tsv'); ?>"><i class="fa fa-floppy-o fa-fw"></i> TSV</a></h4>
						<p class="list-group-item-text">tab-separated, human readable as plaintext</p>
					</div>

					<div class="list-group-item">
						<h4 class="list-group-item-heading"><a href="<?php echo admin_study_url($study->name, 'export_results?format=xls'); ?>"><i class="fa fa-floppy-o fa-fw"></i> XLS</a></h4>
						<p class="list-group-item-text">old excel format, won't work with more than 16384 rows or 256 columns</p>
					</div>

					<div class="list-group-item">
						<h4 class="list-group-item-heading"><a href="<?php echo admin_study_url($study->name, 'export_results?format=xlsx'); ?>"><i class="fa fa-floppy-o fa-fw"></i> XLSX</a></h4>
						<p class="list-group-item-text">new excel format, higher limits</p>
					</div>

					<div class="list-group-item">
						<h4 class="list-group-item-heading"><a href="<?php echo admin_study_url($study->name, 'export_results?format=json'); ?>"><i class="fa fa-floppy-o fa-fw"></i> JSON</a></h4>
						<p class="list-group-item-text">not particularly human-readable, but machines love it. This is probably the fastest way to get your data into R, just use <pre><code class="r hljs">data = as.data.frame(jsonlite::fromJSON("/path/to/exported_file.json"))</code></pre></p>
					</div>
					<div class="list-group-item">
						<h4 class="list-group-item-heading"><i class="fa fa-floppy-o fa-fw"></i> SPSS/SAS/Stata</h4>
						<p class="list-group-item-text">if you want to export to .sav, .dta or other proprietary formats, we recommend importing via the R API and exporting via the haven package. 
							Items will be automatically labelled. If you want, you can use the third line below to also label item values.
						<pre><code class="r hljs">formr_connect("your@email.com", "your_pw")
data = formr_results("survey")
haven::write_sav(data, path = "data.sav")</code></pre></p>
					</div>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
			</div>
		</div>
	</div>
</div>

<!--- MODALS -->

<?php Template::load('admin/footer'); ?>
