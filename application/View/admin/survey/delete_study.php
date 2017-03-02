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
						<h3 class="box-title">Delete Survey <small>with <?= ($resultCount['begun'] + $resultCount['finished']) ?> result rows</small></h3>
					</div>
					<form role="form" method="post" action="<?php echo admin_study_url($study->name, 'delete_study'); ?>">
						<div class="box-body">
							<?php Template::load('public/alerts'); ?>
							<?php if (isset($msg)) {
								echo '<div class="alert ' . $alertclass . '">' . $msg . '</div>';
							} ?>
							<h4 class="hastooltip" title="this is required to avoid accidental deletions">Type survey name to confirm it's deletion</h4>
							<div class="form-group">
								<div class="controls">
									<div class="input-group">
										<span class="input-group-addon"><i class="fa fa-pencil-square"></i></span>
										<input class="form-control" name="delete_confirm" id="delete_confirm" type="text" placeholder="survey name (see up left)" autocomplete="off" required>
									</div>
								</div>
							</div>
						</div>
						<!-- /.box-body -->

						<div class="box-footer">
							<button name="delete" class="btn btn-default btn-danger hastooltip" type="submit"><i class="fa fa-trash-o fa-fw"></i> Delete the entire study permanently (<?= ($resultCount['begun'] + $resultCount['finished']) ?> result rows)</button>
						</div>
					</form>
				</div>

			</div>
			<div class="clear clearfix"></div>
		</div>

	</section>
</div>

<?php Template::load('admin/footer'); ?>
