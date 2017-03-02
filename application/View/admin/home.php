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
				<a href="<?php echo site_url('edit_user'); ?>" class="dashboard-link">
					<span class="icon"><i class="fa fa-user"></i></span>
					<span class="text"> My Account </span>
				</a>
			</div>
			<div class="col-md-3">
				<a href="<?php echo site_url('logout'); ?>" class="dashboard-link">
					<span class="icon"><i class="fa fa-power-off"></i></span>
					<span class="text"> Logout </span>
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
		</div>

	</section>
</div>

<?php Template::load('admin/footer');?>