<?php Template::load('admin/header'); ?>

<div class="content-wrapper">
	<!-- Content Header (Page header) -->
	<section class="content-header">
		<h1><?php echo $run->name; ?> </h1>
	</section>

	<!-- Main content -->
	<section class="content">
		<div class="row">
			<div class="col-md-2">
				<?php Template::load('admin/run/menu'); ?>
			</div>
			<div class="col-md-8">
				<div class="box box-primary">
					<div class="box-header with-border">
						<h3 class="box-title">Empty Run </h3>
					</div>
					<form role="form" action="<?php echo admin_run_url($run->name, 'rename_run'); ?>" method="post">
						<div class="box-body">
							<?php Template::load('public/alerts'); ?>

							<h4>Enter new run shorthand</h4>
							<div class="callout callout-info">
								<ul class="fa-ul fa-ul-more-padding">
									<li><i class="fa-li fa fa-exclamation-triangle"></i> This is the name that users will see in their browser's address bar for your study, possibly elsewhere too.</li>
									<li><i class="fa-li fa fa-unlock"></i> It can be changed later, but it also changes the link to your study, so you probably won't want to change it once you're live.</li>
									<li><i class="fa-li fa fa-lightbulb-o"></i> Ideally, it should be the memorable name of your study.</li>
								</ul>
							</div>
							<div class="form-group">
								<div class="controls">
									<div class="input-group">
										<span class="input-group-addon"><i class="fa fa-pencil-square"></i></span>
										<input class="form-control" name="new_name" type="text" value="<?= $run->name; ?>" placeholder="Name (a to Z, 0 to 9 and _)">
									</div>
								</div>
							</div>
						</div>
						<!-- /.box-body -->

						<div class="box-footer">
							<button name="rename" class="btn btn-primary" type="submit"><i class="fa fa-save"></i>  Save</button>
						</div>
					</form>
				</div>

			</div>
		</div>

		<div class="clear clearfix"></div>
	</section>
	<!-- /.content -->
</div>

<?php Template::load('admin/footer'); ?>