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
						<h3 class="box-title">Survey Results <small> <?= (int) $resultCount['finished'] ?> complete, <?= (int) $resultCount['begun'] ?> begun </small></h3>
						<div class="pull-right">
							<a href="#" data-toggle="modal" data-target="#export-results-table" class="btn btn-primary"><i class="fa fa-save"></i> Export</a>
							<a href="<?php echo admin_study_url($study->name, 'show_results'); ?>" class="btn btn-primary"><i class="fa fa-file-text-o"></i> Main Results</a>

						</div>

					</div>

					<div class="box-body table-responsive no-padding">
						<?php Template::load('public/alerts'); ?>
						<?php if ($results): ?>
							<div class="col-md-12" style="margin: 10px;">
								<form action="<?= admin_study_url($study_name, 'show_itemdisplay') ?>" accept-charset="utf-8" method="get" class="col-md-6">
									<div class="input-group input-group-sm">
										<span class="input-group-addon">Search by session <i class="fa fa-user"></i></span>
										<input type="text" name="session" class="form-control" value="<?= h($session) ?>">
										<span class="input-group-btn">
											<button type="submit" class="btn btn-default btn-flat"><i class="fa fa-search"></i></button>
										</span>
									</div>
								</form>
							</div>
						<?php endif; ?>
						<?php if ($results): ?>
						<table class="table table-hover">
							<thead>
								<tr>
									<?php
										foreach (current($results) as $field => $value):
											if (in_array($field, array("shown_relative", "answered_relative", "item_id", "display_order", "hidden"))) {
												continue;
											}
											echo "<th>{$field}</th>";
										endforeach;
									?>
								</tr>
							</thead>
							<tbody>
								<?php
									// printing table rows
									$last_sess = null;
									foreach ($results as $row):
										$row['created'] = '<abbr title="' . $row['created'] . '">' . timetostr(strtotime($row['created'])) . '</abbr>';
										$row['shown'] = '<abbr title="' . $row['shown'] . ' relative: ' . $row['shown_relative'] . '">' . timetostr(strtotime($row['shown'])) . '</abbr> ';

										if ($row['hidden'] === 1) {
											$row['shown'] .= "<small><em>not shown</em></small>";
										} elseif ($row['hidden'] === null) {
											$row['shown'] .= $row['shown'] . "<small><em>not yet</em></small>";
										}

										// truncate session code
										if ($row['session']) {
											if (($animal_end = strpos($row['session'], "XXX")) === false) {
												$animal_end = 10;
											}
											$short_session = substr($row['session'], 0, $animal_end);
											$row['session'] = '<small><abbr class="abbreviated_session" title="Click to show the full session" data-full-session="'. $row['session'] . '">' . $short_session . '…</abbr></small>';
										}
										$row['saved'] = '<abbr title="' . $row['saved'] . '">' . timetostr(strtotime($row['saved'])) . '</abbr>';
										$row['answered'] = '<abbr title="' . $row['answered'] . ' relative: ' . $row['answered_relative'] . '">' . timetostr(strtotime($row['answered'])) . '</abbr>';
										unset($row['shown_relative'], $row['answered_relative'], $row['item_id'], $row['display_order'], $row['hidden']);

										// open row
										echo $last_sess == $row['unit_session_id'] ? '<tr>' : '<tr class="thick_border_top">';
										$last_sess = $row['unit_session_id'];

										// print cells of row
										// $row is array... foreach( .. ) puts every element of $row to $cell variable
										foreach ($row as $cell):
											echo "<td>$cell</td>";
										endforeach;

										// close row
										echo "</tr>\n";
									endforeach;
								?>
							</tbody>
						</table>
						<div class="col-md-12 pagination text-center">
							<?php $pagination->render("admin/survey/{$study_name}/show_itemdisplay"); ?>
						</div>
						<?php endif; ?>
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
<div class="modal fade" id="export-results-table" tabindex="-1" role="dialog" aria-hidden="true" style="display: none;">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">×</span></button>
				<h4 class="modal-title">Export as..</h4>
			</div>
			<div class="modal-body">
				<div class="list-group col-md-7">
				<h5>Export as</h5>
				<div class="list-group-item">
					<h4 class="list-group-item-heading"><a href="<?= admin_study_url($study->name, 'export_itemdisplay?format=csv')?>"><i class="fa fa-floppy-o fa-fw"></i> CSV</a></h4>
					<p class="list-group-item-text">good for big files, problematic to import into German Excel (comma-separated)</p>
				</div>

				<div class="list-group-item">
					<h4 class="list-group-item-heading"><a href="<?= admin_study_url($study->name, 'export_itemdisplay?format=csv_german')?>"><i class="fa fa-floppy-o fa-fw"></i> German CSV</a></h4>
					<p class="list-group-item-text">good for German Excel (semicolon-separated)</p>
				</div>

				<div class="list-group-item">
					<h4 class="list-group-item-heading"><a href="<?= admin_study_url($study->name, 'export_itemdisplay?format=tsv')?>"><i class="fa fa-floppy-o fa-fw"></i> TSV</a></h4>
					<p class="list-group-item-text">tab-separated, human readable as plaintext</p>
				</div>

				<div class="list-group-item">
					<h4 class="list-group-item-heading"><a href="<?= admin_study_url($study->name, 'export_itemdisplay?format=xls')?>"><i class="fa fa-floppy-o fa-fw"></i> XLS</a></h4>
					<p class="list-group-item-text">old excel format, won't work with more than 16384 rows or 256 columns</p>
				</div>

				<div class="list-group-item">
					<h4 class="list-group-item-heading"><a href="<?= admin_study_url($study->name, 'export_itemdisplay?format=xlsx')?>"><i class="fa fa-floppy-o fa-fw"></i> XLSX</a></h4>
					<p class="list-group-item-text">new excel format, higher limits</p>
				</div>

				<div class="list-group-item">
					<h4 class="list-group-item-heading"><a href="<?= admin_study_url($study->name, 'export_itemdisplay?format=json')?>"><i class="fa fa-floppy-o fa-fw"></i> JSON</a></h4>
					<p class="list-group-item-text">not particularly human-readable, but machines love it. This is probably the fastest way to get your data into R, just use <pre><code class="r hljs">data = as.data.frame(jsonlite::fromJSON("/path/to/exported_file.json"))</code></pre></p>
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
