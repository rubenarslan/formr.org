<?php Template::load('admin/header'); ?>

<div class="content-wrapper">
	<!-- Content Header (Page header) -->
	<section class="content-header">
		<h1><?php echo $run->name; ?> <small><a target="_blank" title="The official link to your run, which you can share with prospective users." href="<?php echo run_url($run->name, null, null) ?>"><?php echo run_url($run->name, null, null) ?></a></small> </h1>
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
						<h3 class="box-title">Create Named Session </h3>
					</div>
					<form role="form" method="post" action="<?= admin_run_url($run->name, 'create_new_named_session') ?>">
						<div class="box-body">
							<?php Template::load('public/alerts'); ?>

							<label class="control-label" for="code_name">
								Choose an identifier/cipher/code name for the user you want to add (if you leave this empty, an entirely random code name will be created).<br>
								You can only use a-Z, 0-9, _ and -.
							</label>
							<div class="form-group">
								<div class="controls">
									<div class="input-group">
										<span class="input-group-addon"><i class="fa fa-pencil-square"></i></span>
										<input class="form-control" name="code_name" id="code_name" type="text" autocomplete="off" placeholder="code name">
									</div>
								</div>
							</div>
						</div>
						<!-- /.box-body -->

						<div class="box-footer">
							<button name="add_user" class="btn btn-success hastooltip" type="submit"><i class="fa fa-ticket fa-fw"></i> Add user</button>
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