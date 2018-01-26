<?php Template::load('admin/header'); ?>

<div class="content-wrapper">
	<!-- Content Header (Page header) -->
	<section class="content-header">
		<h1> Runs</h1>
	</section>

	<!-- Main content -->
	<section class="content">
		<div class="row">
			<div class="col-md-8">
				<div class="box box-primary">
					<div class="box-header with-border">
						<h3 class="box-title" style="display: block"> Runs Listing</h3>
						<div class="box-tools">
							<a href="<?= admin_url('run') ?>" class="btn btn-default"><i class="fa fa-plus-circle"></i> Add New</a>
						</div>
					</div>
					<div class="box-body">
						<?php Template::load('public/alerts'); ?>
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

					</div>
				</div>

			</div>
			<div class="col-md-4">
				<div class="box box-primary">
					<div class="box-body">
						<?php Template::load('public/documentation/run_module_explanations'); ?>
					</div>
				</div>

			</div>
		</div>

		<div class="clear clearfix"></div>
	</section>
	<!-- /.content -->
</div>

<?php
Template::load('admin/run/run_modals', array('reminders' => array()));
Template::load('admin/footer');
?>