<div class="box box-solid">
	<div class="box-header with-border">
		<h3 class="box-title"><i class="fa fa-cogs"></i> Configuration</h3>
		<div class="box-tools">
			<button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button>
		</div>
	</div>
	<div class="box-body no-padding">
		<ul class="nav nav-pills nav-stacked">
			<li><a href="<?= admin_study_url($study->name) ?>"><i class="fa fa-cogs"></i> Settings</a></li>
			<li><a href="<?= admin_study_url($study->name, 'upload_items') ?>"><i class="fa fa-upload"></i> Import Items</a></li>
			<li><a href="<?= admin_study_url($study->name, 'show_item_table') ?>"><i class="fa fa-th"></i> Items Table</a></li>
			<?php if (!empty($google['id'])): ?>
			<li><a href="<?php echo $google['link']; ?>" target="_blank"><i class="fa fa-google"></i> Open Google Sheet</a></li>
			<?php endif; ?>
		</ul>
	</div>
	<!-- /.box-body -->
</div>
<!-- /.box -->

<div class="box box-solid">
	<div class="box-header with-border">
		<h3 class="box-title"><i class="fa fa-th"></i> Testing &amp; Management</h3>
		<div class="box-tools">
			<button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i>
			</button>
		</div>
	</div>
	<div class="box-body no-padding context-menu">
		<ul class="nav nav-pills nav-stacked">
			<li><a target="_blank" href="<?= admin_study_url($study->name, 'access') ?>" class="hastooltip" title="Simply click this link to test this survey. But remember that it's not in the broader context of a run, so if you refer to other surveys, that will cause problems."><i class="fa fa-play"></i> Test Survey</a></li>
			<?php if (!$study->settings['hide_results']): ?>
			<li><a href="<?= admin_study_url($study->name, 'show_results') ?>"><i class="fa fa-file-text-o"></i> Show Results</a></li>
			<li class="dropdown"><a  href="#" data-toggle="dropdown" aria-expanded="false" class="dropdown-toggle"><i class="fa fa-save"></i> Export Results</a>
				<ul class="dropdown-menu">
					<li><a href="<?= admin_study_url($study->name, 'export_results?format=csv'); ?>"><i class="fa fa-floppy-o"></i> Download CSV</a></li>
					<li><a href="<?= admin_study_url($study->name, 'export_results?format=csv_german'); ?>"><i class="fa fa-floppy-o"></i> Download German CSV</a></li>
					<li><a href="<?= admin_study_url($study->name, 'export_results?format=tsv'); ?>"><i class="fa fa-floppy-o"></i> Download TSV</a></li>
					<li><a href="<?= admin_study_url($study->name, 'export_results?format=xls'); ?>"><i class="fa fa-floppy-o"></i> Download XLS</a></li>
					<li><a href="<?= admin_study_url($study->name, 'export_results?format=xlsx'); ?>"><i class="fa fa-floppy-o"></i> Download XLSX</a></li>
					<li><a href="<?= admin_study_url($study->name, 'export_results?format=json'); ?>"><i class="fa fa-floppy-o"></i> Download JSON</a></li>
				</ul>
			</li>
			<?php endif; ?>
		</ul>
	</div>
	<!-- /.box-body -->
</div>

<!-- /. box -->
<div class="box box-solid collapsed-box">
	<div class="box-header with-border">
		<h3 class="box-title"><i class="fa fa-bolt"></i> Danger Zone</h3>
		<div class="box-tools">
			<button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-plus"></i></button>
		</div>
	</div>
	<div class="box-body no-padding">
		<ul class="nav nav-pills nav-stacked">
			<li><a href="<?php echo admin_study_url($study->name, 'delete_study'); ?>" class="hastooltip" title="Go to deletion dialog, does not delete yet"><i class="fa fa-trash text-red"></i> Delete Study</a></li>
			<li><a href="<?php echo admin_study_url($study->name, 'delete_results'); ?>" class="hastooltip" title="Go to deletion dialog, does not delete yet"><i class="fa fa-trash text-red"></i> Delete Results</a></li>
			<li><a href="<?php echo admin_study_url($study->name, 'rename_study'); ?>" class="hastooltip" title="Rename your survey, but be careful, if you've referred to it by name somewhere in the run or in other surveys."><i class="fa fa-edit text-red"></i> Rename Study</a></li>
		</ul>
	</div>
	<!-- /.box-body -->
</div>
<!-- /.box -->
<?php 
	if (empty($resultCount)) {
		 $resultCount = $study->getResultCount();
	}
	if (trim($study->settings['google_file_id']) && (int)$resultCount['real_users'] === 0):
	$google_link = google_get_sheet_link($study->settings['google_file_id']);
?>
	<form class="" action="<?= admin_study_url($study->name, 'upload_items') ?>" enctype="multipart/form-data"  id="upload_items" name="upload_items" method="post" action="#">
		<input type="hidden" name="study_id" value="<?= $study->id ?>">
		<input type="hidden" name="google_sheet" value="<?php echo h($google_link); ?>">

		<button class="btn btn-lg" type="submit" title="Will update the item table from the Google Sheet. This button only works before the survey contains real data."><small> <i class="fa-fw fa fa-pencil-square"></i> Quick-import items</small></button>
	</form>
<?php endif; ?>
<hr />
<a href="<?= admin_url('survey') ?>" class="btn btn-primary btn-block margin-bottom"><i class="fa fa-plus-circle"></i> Add Survey</a>
