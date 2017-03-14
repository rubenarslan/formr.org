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
			<div class="col-md-10">
				<div class="box box-primary">
					<div class="box-header with-border">
						<h3 class="box-title">Log of user activity</h3>
					</div>
					<div class="box-body">
						<h4>
							Here you can see users' history of participation, i.e. when they got to certain point in a study, how long they stayed at each station and so forth. Earliest participants come first.
						</h4>
					</div>
					<div class="box-body table-responsive no-padding">
						<?php Template::load('public/alerts'); ?>
						
						<div class="col-md-12" style="margin: 10px;">
							<form action="<?= admin_run_url($run->name, 'user_detail') ?>" method="get" class="form-inline">
								<label class="sr-only">Name</label>
								<div class="input-group" style="width: 350px;">
									<div class="input-group-addon">SEARCH <i class="fa fa-user"></i></div>
									<input name="session" value="<?= h(array_val($_GET, 'session')) ?>" type="text" class="form-control" placeholder="Session code">
								</div>

								<label class="sr-only" title="This refers to the user's current position!">Position</label>
								<div class="input-group">
									<div class="input-group-addon"><i class="fa fa-compass"></i></div>
									<input name="position" value="<?= h(array_val($_GET, 'position')) ?>" type="number" class="form-control" placeholder="Position">
								</div>

								<label class="sr-only">Operator</label>
								<div class="input-group">
									<select class="form-control" name="position_lt">
										<option value="=" <?= ($position_lt == '=') ? 'selected' : ''; ?>>=</option>
										<option value="&lt;" <?= ($position_lt == '<') ? 'selected' : ''; ?>>&lt;</option>
										<option value="&gt;" <?= ($position_lt == '>') ? 'selected' : ''; ?>>&gt;</option>
									</select>
								</div>


								<button type="submit" class="btn btn-default"><i class="fa fa-search"></i></button>
							</form>
						</div>

						<?php if (!empty($users)): ?>
							<table class="table table-hover">
								<thead>
									<tr>
										<?php
										foreach (current($users) AS $field => $value) {
											if ($field === 'created' || $field === 'ended' || $field === 'expired') {
												continue;
											}
											echo "<th>{$field}</th>";
										}
										?>
									</tr>
								</thead>
								<tbody>
									<?php
									$last_ended = $last_user = $continued = $user_class = '';
									// printing table rows
									foreach ($users as $row) {
										if ($row['Session'] !== $last_user) { // next user
											$user_class = ($user_class == '') ? 'alternate' : '';
											$last_user = $row['Session'];
										} elseif (round((strtotime($row['created']) - $last_ended) / 30) == 0) { // same user
											$continued = ' immediately_continued';
										}
										$last_ended = strtotime($row['created']);
										unset($row['created'], $row['ended'], $row['expired']);


										echo '<tr class="' . $user_class . $continued . '">';
										$continued = '';
										foreach ($row as $cell) {
											echo "<td>$cell</td>";
										}
										echo "</tr>\n";
									}
									?>
								</tbody>
							</table>
							<div class="pagination">
								<?php
								$append = $querystring ? "?" . http_build_query($querystring) . "&" : '';
								$pagination->render("admin/run/" . $run->name . "/user_detail" . $append);
								?>
							</div>
						<?php endif; ?>

					</div>
				</div>

			</div>
		</div>

		<div class="clear clearfix"></div>
	</section>
	<!-- /.content -->
</div>

<?php Template::load('admin/footer'); ?>