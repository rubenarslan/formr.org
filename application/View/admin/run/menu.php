<div class="box box-solid">
	<div class="box-header with-border">
		<h3 class="box-title"><i class="fa fa-th"></i> Menu</h3>
		<div class="box-tools">
			<button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button>
		</div>
	</div>
	<div class="box-body no-padding">
		<ul class="nav nav-pills nav-stacked">
			<li><a href="<?php echo run_url($run->name) ?>" target="_blank" title="Click this link to play the run as yourself. Remember that the run saves your position and progress, so you will go where you left off. If you're testing a diary, this may be desirable, if you're testing a one-shot survey, maybe not so."><i class="fa fa-play"></i> Test as yourself</a></li>
			<li><a href="<?php echo admin_run_url($run->name, 'create_new_test_code'); ?>" title="Generate a new test code, to test as a new user."><i class="fa fa-stethoscope"></i> New test code</a></li>
			<li><a href="<?php echo admin_run_url($run->name, 'user_overview'); ?>" title="Here you can monitor users' progress, send them to a different position and send them manual reminders."><i class="fa fa-users"></i> Users</a></li>
			<li><a href="<?php echo admin_run_url($run->name, 'overview'); ?>" title="Here you can monitor your custom progress indicators, do preliminary data analysis and so on."><i class="fa fa-eye"></i> Overview</a></li>
		</ul>
	</div>
	<!-- /.box-body -->
</div>
<!-- /.box -->

<div class="box box-solid">
	<div class="box-header with-border">
		<h3 class="box-title"><i class="fa fa-cogs"></i> Configuration</h3>
		<div class="box-tools">
			<button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button>
		</div>
	</div>
	<div class="box-body no-padding">
		<ul class="nav nav-pills nav-stacked">
			<li><a href="<?php echo admin_run_url($run->name); ?>"><i class="fa fa-edit"></i> Edit Run</a></li>
			<li><a href="<?php echo admin_run_url($run->name, 'settings'); ?>"><i class="fa fa-cogs"></i> Settings</a></li>
			<li><a href="<?php echo admin_run_url($run->name, 'upload_files'); ?>" title="Upload images, videos, sounds and the like."><i class="fa fa-upload"></i> Upload Files</a></li>
		</ul>
	</div>
	<!-- /.box-body -->
</div>
<!-- /.box -->

<div class="box box-solid collapsed-box">
	<div class="box-header with-border">
		<h3 class="box-title"><i class="fa fa-file"></i> Logs</h3>
		<div class="box-tools">
			<button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-plus"></i></button>
		</div>
	</div>
	<div class="box-body no-padding">
		<ul class="nav nav-pills nav-stacked">
			<li><a href="<?php echo admin_run_url($run->name, 'user_detail'); ?>" title="Here you'll see users' entire history of participation, i.e. when they left which position etc."><i class="fa fa-user"></i> User Details</a></li>
			<li><a href="<?php echo admin_run_url($run->name, 'random_groups'); ?>" title="This is simply your log of how users have been randomised"><i class="fa fa-random"></i> Random Group</a></li>
			<li><a href="<?php echo admin_run_url($run->name, 'email_log'); ?>" title="The log of every email sent from this run."><i class="fa fa-envelope" title="The log of every email sent from this run."></i> Emails Sent</a></li>
			<li><a href="<?php echo admin_run_url($run->name, 'cron_log'); ?>" title="The log of everything that happened without user interaction, i.e. when you click 'Play', like sending email reminders and checking whether pauses are over."><i class="fa fa-cog"></i> Cron </a></li>
			<li class="dropdown"><a  href="#" data-toggle="dropdown" aria-expanded="false" class="dropdown-toggle"><i class="fa fa-save"></i> Export Data</a>
				<ul class="dropdown-menu">
					<li><a href="<?php echo admin_run_url($run->name, 'export_data?format=csv'); ?>"><i class="fa fa-floppy-o"></i> Download CSV</a></li>
					<li><a href="<?php echo admin_run_url($run->name, 'export_data?format=csv_german'); ?>"><i class="fa fa-floppy-o"></i> Download German CSV</a></li>
					<li><a href="<?php echo admin_run_url($run->name, 'export_data?format=tsv'); ?>"><i class="fa fa-floppy-o"></i> Download TSV</a></li>
					<li><a href="<?php echo admin_run_url($run->name, 'export_data?format=xls'); ?>"><i class="fa fa-floppy-o"></i> Download XLS</a></li>
					<li><a href="<?php echo admin_run_url($run->name, 'export_data?format=xlsx'); ?>"><i class="fa fa-floppy-o"></i> Download XLSX</a></li>
					<li><a href="<?php echo admin_run_url($run->name, 'export_data?format=json'); ?>"><i class="fa fa-floppy-o"></i> Download JSON</a></li>
				</ul>
			</li>
		</ul>
	</div>
	<!-- /.box-body -->
</div>
<!-- /.box -->

<div class="box box-solid collapsed-box">
	<div class="box-header with-border">
		<h3 class="box-title"><i class="fa fa-bolt"></i> Danger Zone</h3>
		<div class="box-tools">
			<button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-plus"></i></button>
		</div>
	</div>
	<div class="box-body no-padding">
		<ul class="nav nav-pills nav-stacked">
			<li><a href="<?= admin_run_url($run->name, 'delete_run'); ?>"><i class="fa fa-trash text-red"></i> Delete Run</a></li>
			<li><a href="<?= admin_run_url($run->name, 'empty_run'); ?>"><i class="fa fa-trash text-red"></i> Empty Run</a></li>
			<li><a href="<?= admin_run_url($run->name, 'rename_run'); ?>"><i class="fa fa-edit text-red"></i> Rename Run</a></li>
		</ul>
	</div>
	<!-- /.box-body -->
</div>
<!-- /.box -->
<a href="<?php echo admin_url('run/add_run'); ?>" class="btn btn-primary btn-block margin-bottom"><i class="fa fa-plus-circle"></i> Add Run</a>
