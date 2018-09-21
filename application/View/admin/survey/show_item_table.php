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
						<h3 class="box-title">Survey Items</h3>
					</div>

					<div class="box-body">
						<?php Template::load('public/alerts'); ?>
						<div class="callout callout-info">
							<p>
								Click the "Show Items" button to show an overview table of your items. To leave the overview, press <kbd>esc</kbd> or click the close button in the top or bottom right.
							</p>
							<p>
								You can download your item table in different formats (.xls, .xlsx, .json), but take care: The downloaded table
								may not exactly match the uploaded table (most importantly, choice labels are always relegated to a second sheet).
							</p>
						</div>

						<div class="row">
							<div class="col-md-4">
								<a href="#" class="dashboard-link" data-toggle="modal" data-target="#download-items">
									<span class="icon"><i class="fa fa-download"></i></span>
									<span class="text">Download Items</span>
								</a>
							</div>

							<div class="col-md-4">
								<a href="#" class="dashboard-link" data-toggle="modal" data-target="#show-items">
									<span class="icon"><i class="fa fa-th"></i></span>
									<span class="text">Show Items</span>
								</a>
							</div>
							
							<?php if (!empty($google['id'])): ?>
							<div class="col-md-4">
								<a href="<?php echo $google['link']; ?>" class="dashboard-link" target="_blank">
									<span class="icon"><i class="fa fa-google"></i></span>
									<span class="text">Google Sheet</span>
								</a>
							</div>
							<?php endif ?>
							
						</div>
					</div>
				</div>


			</div>
		</div>

	</section>
	<!-- /.content -->
</div>
<!-- /.content-wrapper -->

<!-- dialog for items in items table -->
<div class="modal fade admin-survey-items" id="show-items" tabindex="-1" role="dialog" aria-labelledby="ItemsTable" aria-hidden="true">
	<div class="modal-dialog table-content">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
				<h4 class="modal-title">Survey Items</h4>
			</div>
			<div class="modal-body">
				<?php Template::load('admin/survey/show_item_table_table', array('results' => $results)); ?>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
			</div>
		</div>
	</div>
</div>

<!-- dialog for download formats -->
<div class="modal fade" id="download-items" tabindex="-1" role="dialog" aria-labelledby="downloadItemTable" aria-hidden="true">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
				<h4 class="modal-title">Export as..</h4>
			</div>
			<div class="modal-body">
				<?php if ($original_file): ?>
					<div class="list-group-item">
						<h4 class="list-group-item-heading"><a href="<?php echo admin_study_url($study->name, 'export_item_table?format=original'); ?>"><i class="fa fa-floppy-o fa-fw"></i> ORIGINAL</a></h4>
						<p class="list-group-item-text">Downloads the latest uploaded items sheet</p>
					</div>
				<?php endif; ?>
				<?php if ($google_id): ?>
					<div class="list-group-item">
						<h4 class="list-group-item-heading"><a href="https://docs.google.com/spreadsheets/d/<?php echo $google_id; ?>" target="blank"><i class="fa fa-link fa-fw"></i> GOOGLE SHEET</a></h4>
						<p class="list-group-item-text">Opens google sheet in new tab</p>
					</div>
				<?php endif; ?>
				<div class="list-group-item">
					<h4 class="list-group-item-heading"><a href="<?php echo admin_study_url($study->name, 'export_item_table?format=xls'); ?>"><i class="fa fa-floppy-o fa-fw"></i> XLS</a></h4>
					<p class="list-group-item-text">old excel format, won't work with more than 16384 rows or 256 columns</p>
				</div>

				<div class="list-group-item">
					<h4 class="list-group-item-heading"><a href="<?php echo admin_study_url($study->name, 'export_item_table?format=xlsx'); ?>"><i class="fa fa-floppy-o fa-fw"></i> XLSX</a></h4>
					<p class="list-group-item-text">new excel format, higher limits</p>
				</div>

				<div class="list-group-item">
					<h4 class="list-group-item-heading"><a href="<?php echo admin_study_url($study->name, 'export_item_table?format=json'); ?>"><i class="fa fa-floppy-o fa-fw"></i> JSON</a></h4>
					<p class="list-group-item-text">not particularly human-readable, but machines love it. This is probably the fastest way to get your data into R, just use </p>
					<pre><code class="r hljs">data = as.data.frame(jsonlite::fromJSON("/path/to/exported_file.json"))</code></pre>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
			</div>
		</div>
	</div>
</div>

<?php Template::load('admin/footer'); ?>
