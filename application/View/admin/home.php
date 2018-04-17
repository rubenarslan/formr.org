<?php Template::load('admin/header'); ?>

<div class="content-wrapper">
	<section class="content-header">
		<h1>Dashboard <small>Quick Links</small></h1>
	</section>

	<section class="content">

		<?php Template::load('public/alerts'); ?>
		<div class="row">
			<div class="col-md-3">
				<a href="<?php echo site_url('documentation'); ?>" class="dashboard-link">
					<span class="icon"><i class="fa fa-book"></i></span>
					<span class="text">Documentation</span>
				</a>
			</div>

			<div class="col-md-3">
				<a href="<?php echo admin_url('survey'); ?>" class="dashboard-link">
					<span class="icon"><i class="fa fa-pencil-square"></i></span>
					<span class="text"><i class="fa fa-plus-circle"></i> Create Survey</span>
				</a>
			</div>

			<div class="col-md-3">
				<a href="<?php echo admin_url('run'); ?>" class="dashboard-link">
					<span class="icon"><i class="fa fa-file-text-o"></i></span>
					<span class="text"><i class="fa fa-plus-circle"></i> Create Run</span>
				</a>
			</div>

			<div class="col-md-3">
				<a href="<?php echo admin_url('mail'); ?>" class="dashboard-link">
					<span class="icon"><i class="fa fa-envelope"></i></span>
					<span class="text"> Mail Accounts</span>
				</a>
			</div>
		</div>
		<div class="row">
			<div class="col-md-3">
				<a href="<?php echo site_url('account'); ?>" class="dashboard-link">
					<span class="icon"><i class="fa fa-user"></i></span>
					<span class="text"> My Account </span>
				</a>
			</div>
			<div class="col-md-3">
				<a href="<?php echo site_url('documentation/#help'); ?>" class="dashboard-link">
					<span class="icon"><i class="fa fa-question-circle"></i></span>
					<span class="text"> Help </span>
				</a>
			</div>

			<div class="col-md-3">
				<a href="<?php echo site_url(); ?>" class="dashboard-link">
					<span class="icon"><i class="fa fa-globe"></i></span>
					<span class="text"> Go to Site </span>
				</a>
			</div>

			<div class="col-md-3">
				<a href="<?php echo site_url('logout'); ?>" class="dashboard-link">
					<span class="icon"><i class="fa fa-power-off"></i></span>
					<span class="text"> Logout </span>
				</a>
			</div>
		</div>

	</section>
	
	
	<section class="content">
		<div class="row">
			<div class="col-lg-6">
				<div class="box box-info">
					<div class="box-header with-border">
						<h3 class="box-title">Recent Runs</h3>
					</div>
					<!-- /.box-header -->
					<div class="box-body">
						<div class="table-responsive">
							<table class="table no-margin">
								<thead>
									<tr>
										<th># ID</th>
										<th>Name</th>
										<th>Created</th>
										<th>Status</th>
										<th>Cron</th>
										<th>Lock</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($runs as $d_run): ?>
										<tr>
											<td>#<?php echo $d_run['id']; ?></td>
											<td><a href="<?php echo admin_run_url($d_run['name']); ?>"><?php echo $d_run['name']; ?></a></td>
											<td><?php echo $d_run['created']; ?></td>
											<td> <?php 
												if ($d_run['public'] == 0) {
													echo '<span class="label label-danger">PRIVATE</span>';
												} elseif ($d_run['public'] == 1) {
													echo '<span class="label label-info">ACCESS CODE ONLY</span>';
												} elseif ($d_run['public'] == 2) {
													echo '<span class="label label-default">LINK ONLY</span>';
												} elseif ($d_run['public'] == 3) {
													echo '<span class="label label-success">PUBLIC</span>';
												}
											?></td>
											<td><span class="label label-<?php echo $d_run['cron_active'] ? 'success' : 'default'; ?>"><?php echo $d_run['cron_active'] ? 'ACTIVE' : 'NOT ACTIVE'; ?></span></td>
											<td><span class="label label-<?php echo $d_run['locked'] ? 'danger' : 'info'; ?>"><?php echo $d_run['locked'] ? 'LOCKED' : 'UNLOCKED'; ?></span></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<!-- /.table-responsive -->
					</div>
					<div class="box-footer clearfix">
						<a href="<?php echo admin_url('run/list'); ?>" class="btn btn-sm btn-default btn-flat pull-right">View All</a>
					</div>
				</div>
			</div>
			<div class="col-lg-6">
				<div class="box box-danger">
					<div class="box-header with-border">
						<h3 class="box-title">Recent Surveys</h3>
					</div>
					<div class="box-body ">
						<div class="table-responsive">
							<table class="table no-margin">
								<thead>
									<tr>
										<th># ID</th>
										<th>Name</th>
										<th>Created</th>
										<th>Modified</th>
										<th>Google Sheet</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($studies as $d_study): ?>
										<tr>
											<td>#<?php echo $d_study['id']; ?></td>
											<td><a href="<?php echo admin_study_url($d_study['name']); ?>"><?php echo $d_study['name']; ?></a></td>
											<td><?php echo $d_study['created']; ?></td>
											<td><?php echo $d_study['modified']; ?></td>
											<td>
												<?php if ($d_study['google_file_id']): ?>
												<a href="<?php echo google_get_sheet_link($d_study['google_file_id']); ?>" target="_blank"><?php echo substr($d_study['google_file_id'], 0, 8); ?>...<i class="fa fa-external-link-square"></i></a>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<!-- /.table-responsive -->
					</div>
					<div class="box-footer clearfix">
						<a href="<?php echo admin_url('survey/list'); ?>" class="btn btn-sm btn-default btn-flat pull-right">View All</a>
					</div>
				</div>

			</div>
		</div>
	</section>
	
	
</div>

<?php Template::load('admin/footer');?>