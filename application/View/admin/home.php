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
	
	
	<section class="content">
		<div class="row">
			<div class="col-md-12 dashboard-small-links">
				
				<h2>Runs</h2>
				<?php if(empty($runs)): ?>
					<i>You haven't created any run yet! <a href="<?php echo admin_url('run'); ?>"> Create new Run</a>.</i>
				<?php else: ?>
					<?php foreach ($runs as $d_run): ?>
					<a class="dashboard-small-link" href="<?php echo admin_run_url($d_run['name']); ?>"><?php echo $d_run['name']; ?></a>
					<?php endforeach; ?>
				<?php endif; ?>

				<h2>Surveys</h2>
				<?php if(empty($studies)): ?>
					<i>You haven't created any surveys yet! <a href="<?php echo admin_url('survey'); ?>"> Create new Survey</a>.</i>
				<?php else: ?>
					<?php foreach ($studies as $d_study): ?>
					<a class="dashboard-small-link" href="<?php echo admin_study_url($d_study['name']); ?>"><?php echo $d_study['name']; ?></a>
					<?php endforeach; ?>
				<?php endif; ?>
				
			</div>
		</div>
	</section>
	
	
</div>

<?php Template::load('admin/footer');?>