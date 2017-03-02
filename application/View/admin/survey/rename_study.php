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

			<div class="col-md-8">
				<div class="box box-primary">
					<div class="box-header with-border">
						<h3 class="box-title">Rename Survey </h3>
					</div>
					<form role="form" method="post" action="<?php echo admin_study_url($study->name, 'rename_study'); ?>">
						<div class="box-body">
							<?php Template::load('public/alerts'); ?>
							<?php
							if (isset($msg)) {
								echo '<div class="alert ' . $alertclass . '">' . $msg . '</div>';
							}
							?>
							<h4>Choose a new name for your study</h4>
							<div class="form-group">
								<div class="controls">
									<div class="input-group">
										<span class="input-group-addon"><i class="fa fa-edit"></i></span>
										<input class="form-control" name="new_name" id="new_name" type="text" placeholder="survey name" value="<?= $study_name; ?>" autocomplete="off" />
									</div>
								</div>
							</div>
						</div>

						<div class="box-footer">
							<button name="rename" class="btn btn-default btn-danger hastooltip" type="submit"><i class="fa fa-unlock fa-fw"></i> Rename this study</button>
						</div>
					</form>
				</div>

			</div>
			<div class="clear clearfix"></div>
		</div>

	</section>
</div>

<?php Template::load('admin/footer'); ?>
