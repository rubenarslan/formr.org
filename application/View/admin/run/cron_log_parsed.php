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
						<h3 class="box-title">Cron Log </h3>
					</div>
					<div class="box-body">
						<p>
							The cron job runs every x minutes, to evaluate whether somebody needs to be sent a mail. This usually happens if a pause is over. It will then skip forward or backward, send emails and shuffle participants, but will stop at surveys and pages, because those should be viewed by the user.
						</p>
						<div class="cron-log">
							<div id="log-entries" class="log-entries panel-group opencpu_accordion">
								<?php
								if ($parse) {
									$parser->printCronLogFile($parse);
								}
								?>
							</div>
						</div>

						<script>
							jQuery(document).ready(function () {
								var $entries = jQuery('#log-entries');
								var items = $entries.children('.log-entry');
								$entries.append(items.get().reverse());
								$entries.show();
							});
						</script>
					</div>
				</div>

			</div>
		</div>

		<div class="clear clearfix"></div>
	</section>
	<!-- /.content -->
</div>

<?php Template::load('admin/footer'); ?>