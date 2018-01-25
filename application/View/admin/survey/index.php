<?php Template::load('admin/header'); ?>

<div class="content-wrapper">

	<section class="content-header">
		<h1><?= $study->name ?> <small>Survey ID: <?= $survey_id ?></small></h1>
	</section>

	<section class="content">
		<div class="row">
			<div class="col-md-2">
				<?php Template::load('admin/survey/menu'); ?>
			</div>

			<div class="col-md-8">
				<div class="box box-primary">
					<div class="box-header with-border">
						<h3 class="box-title">Survey Shortcuts</h3>
					</div>
					<div class="box-body">
						<div class="row">
							<div class="col-md-4">
								<a href="<?= admin_study_url($study->name, 'show_item_table') ?>" class="dashboard-link">
									<span class="icon"><i class="fa fa-download"></i></span>
									<span class="text">Download Items</span>
								</a>
							</div>

							<div class="col-md-4">
								<a href="<?= admin_study_url($study->name, 'show_item_table') ?>" class="dashboard-link">
									<span class="icon"><i class="fa fa-th"></i></span>
									<span class="text">Show Items</span>
								</a>
							</div>
							<div class="col-md-4">
								<a href="<?= admin_study_url($study->name, 'upload_items') ?>" class="dashboard-link">
									<span class="icon"><i class="fa fa-upload"></i></span>
									<span class="text">Upload Items</span>
								</a>
							</div>
						</div>
					</div>
					
					<div class="box-header with-border">
						<h3 class="box-title">Survey Settings</h3>
					</div>
					<form role="form" method="post" action="<?php echo admin_study_url($study->name); ?>">
						<div class="box-body">
							<?php Template::load('public/alerts'); ?>

							<div class="callout callout-info">
								<p>These are some settings for advanced users. You'll mostly need the "Import items" and the "Export results" options to the left.</p>
							</div>
							<table class="table table-striped editstudies">
								<thead>
									<tr>
										<th>Option</th>
										<th style="width:200px">Value</th>
									</tr>
								</thead>
								<tbody>
									<?php
									foreach ($study->settings as $key => $value):
										echo "<tr>";
										$help = '';
										if ($key == "expire_after")
											$help = ' <i class="fa fa-info-circle hastooltip" title="Should the survey expire after a certain number of minutes of inactivity? Specify 0 if not. If a user is never active for x minutes or if the last activity is more than x minutes ago, the run will automatically move on."></i>';
										elseif ($key == "enable_instant_validation")
											$help = ' <i class="fa fa-info-circle hastooltip" title="Instant validation means that users will be alerted if their survey input is invalid right after entering their information. Otherwise, validation messages will only be shown once the user tries to submit."></i>';
										elseif ($key == "maximum_number_displayed")
											$help = ' <i class="fa fa-info-circle hastooltip" title="Do you want a certain number of items on each page? We prefer speciyfing pages manually (by adding submit buttons items when we want a pagebreaks) because this gives us greater manual control."></i>';
										elseif ($key == "add_percentage_points")
											$help = ' <i class="fa fa-info-circle hastooltip" title="Sometimes, in complex studies where several surveys are linked, you\'ll want to let the progress bar that the user sees only vary in a given range (e.g. first survey 0-40, second survey 40-100). This is the lower limit for this survey."></i>';
										elseif ($key == "displayed_percentage_maximum")
											$help = ' <i class="fa fa-info-circle hastooltip" title="Sometimes, in complex studies where several surveys are linked, you\'ll want to let the progress bar that the user sees only vary in a given range (e.g. first survey 0-40, second survey 40-100). This is the upper limit for this survey."></i>';
										elseif ($key == "unlinked")
											$help = ' <i class="fa fa-info-circle hastooltip" title="If you set this to 1, you won\'t be able to set it back to 0. Unlinking a survey means that the results will only be shown in random order, without session codes and dates and only after a minimum of 10 results are in. This is meant as a way to anonymise personally identifiable data and separate it from the survey data that you will analyze."></i>';
										elseif ($key == "google_file_id")
											$help = ' <i class="fa fa-info-circle hastooltip" title="This ID links to a Google Spreadsheet. You can use one to make it easier to work on spreadsheets collaboratively."></i>';
										echo "<td>" . h(str_replace("_", " ", $key)) . $help . "</td>";

										echo "<td><input class=\"form-control\" type=\"text\" size=\"50\" name=\"" . h($key) . "\" value=\"" . h($value) . "\"/></td>";
										echo "</tr>";
									endforeach;
									?>
								</tbody>
							</table>
						</div>
						<!-- /.box-body -->

						<div class="box-footer">
							<button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Settings</button>
						</div>
					</form>
				</div>
			</div>
			<div class="clear clearfix"></div>
		</div>

	</section>
	<!-- /.content -->
</div>
<!-- /.content-wrapper -->

<?php
Template::load('admin/footer');
