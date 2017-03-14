<?php Template::load('admin/header'); ?>

<div class="content-wrapper">
	<!-- Content Header (Page header) -->
	<section class="content-header">
		<h1>User Management <small>Superadmin</small></h1>
	</section>

	<!-- Main content -->
	<section class="content">
		<div class="row">
			<div class="col-md-12">
				<div class="box box-primary">
					<div class="box-header with-border">
						<h3 class="box-title">Formr active users </h3>
					</div>
					<div class="box-body table-responsive">
						<?php if (!empty($users)): ?>
							<table class='table table-striped'>
								<thead>
									<tr>
										<?php
										foreach (current($users) AS $field => $value):
											echo "<th>{$field}</th>";
										endforeach;
										?>
									</tr>
								</thead>
								<tbody>
									<?php
									// printing table rows
									foreach ($users AS $row):
										// $row is array... foreach( .. ) puts every element
										// of $row to $cell variable
										echo "<tr>";
										foreach ($row as $cell):
											echo "<td>$cell</td>";
										endforeach;
										echo "</tr>";
									endforeach;
									?>
								</tbody>
							</table>
							<div class="pagination">
								<?php $pagination->render("superadmin/active_users"); ?>
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