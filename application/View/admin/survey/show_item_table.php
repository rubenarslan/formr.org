<?php
$results = $study->getItemsWithChoices();
Template::load('header');
Template::load('acp_nav');
?>
<div class="row">
	<div class="col-lg-6 col-sm-7 col-md-8">
		
		<div class="transparent_well col-md-12" style="padding-bottom: 20px;">

		<h2>Item table <small>currently active</small></h2>


		<h4>
			<a href="#" data-toggle="modal" data-target="#download" class="btn btn-default"><i class="fa fa-download"></i> Download item table</a>
			<a href="#" data-toggle="modal" data-target="#admin-survey-items" id="show-survey-items" class="btn btn-default"><i class="fa fa-list"></i> Show Items</a>
		</h4>
		
				<p>
					Click the "Show Items" button to show an overview table of your items. To leave the overview, press <kbd>esc</kbd> or click the close button in the top or bottom right.
				</p>
				<p>
					You can download your item table in different formats (.xls, .xlsx, .json), but take care: The downloaded table
					may not exactly match the uploaded table (most importantly, choice labels are always relegated to a second sheet).
				</p>
		</div>

		<!-- dialog for items in items table -->
		<div class="modal fade admin-survey-items" id="admin-survey-items" tabindex="-1" role="dialog" aria-labelledby="ItemsTable" aria-hidden="true">
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
		<div class="modal fade" id="download" tabindex="-1" role="dialog" aria-labelledby="downloadItemTable" aria-hidden="true">
			<div class="modal-dialog">
				<div class="modal-content">
					<div class="modal-header">
						<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
						<h4 class="modal-title">Export as..</h4>
					</div>
					<div class="modal-body">
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

	</div>
</div>
<script type="text/javascript">
jQuery(document).ready(function() {
	$('#show-survey-items').trigger('click');
});
</script>

<?php
Template::load('footer');
