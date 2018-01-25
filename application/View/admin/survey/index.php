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
					<form role="form" method="post" action="<?php echo admin_study_url($study->name); ?>">
						<div class="box-header with-border">
							<h3 class="box-title">Survey Settings</h3>
						</div>

						<div class="box-body">
							<?php Template::load('public/alerts'); ?>

							<div class="callout callout-info">
								<p>These are some settings for advanced users. You'll mostly need the "Import items" and the "Export results" options to the left.</p>
							</div>


							<table class="table editstudies">
								<tr>
									<td>
										<label>Items Per Page</label>
										<span class="help-block">
											<i class="fa fa-info-circle"></i> Do you want a certain number of items on each page? We prefer speciyfing pages manually (by adding submit buttons items when we want a pagebreaks) because this gives us greater manual control
										</span>
										<span class="col-md-6 nlp" style="padding-left: 0px;">
											<input type="number" class="form-control" name="maximum_number_displayed" value="<?= h($study->settings['maximum_number_displayed']) ?>" min="0" />
										</span>


									</td>
									<td>
										<label>Enable Instant Validation</label>
										<span class="help-block">
											<i class="fa fa-info-circle"></i> Instant validation means that users will be alerted if their survey input is invalid right after entering their information. Otherwise, validation messages will only be shown once the user tries to submit.
										</span>
										<div class="checkbox">
											<label> <input type="checkbox" name="enable_instant_validation" value="1" <?php if ($study->settings['enable_instant_validation']) echo 'checked="checked"'; ?>> <strong>Enable</strong> </label>
										</div>
									</td>
								</tr>

								<tr>
									<td>
										<label>Maximum Percentage Display</label>
										<span class="help-block">
											<i class="fa fa-info-circle"></i> Sometimes, in complex studies where several surveys are linked, you'll want to let the progress bar that the user sees only vary in a given range (e.g. first survey 0-40, second survey 40-100). This is the upper limit for this survey.
										</span>
										<span class="col-md-6 nlp" style="padding-left: 0px;">
											<input type="number" class="form-control" name="displayed_percentage_maximum" value="<?= h($study->settings['displayed_percentage_maximum']) ?>" min="0" max="100" />
										</span>


									</td>
									<td>
										<label>Minimum Percentage Display</label>
										<span class="help-block">
											<i class="fa fa-info-circle"></i> Sometimes, in complex studies where several surveys are linked, you'll want to let the progress bar that the user sees only vary in a given range (e.g. first survey 0-40, second survey 40-100). This is the lower limit for this survey.
										</span>
										<span class="col-md-6 nlp" style="padding-left: 0px;">
											<input type="number" class="form-control" name="add_percentage_points" value="<?= h($study->settings['add_percentage_points']) ?>" min="0" max="100" />
										</span>
									</td>
								</tr>

								<tr>
									<td>
										<label>Survey Unlinking</label>
										<span class="help-block">
											<i class="fa fa-info-circle"></i> 
											Unlinking a survey means that the results will only be shown in random order, without session codes and dates and only after a minimum of 10 results are in. This is meant as a way to anonymise personally identifiable data and separate it from the survey data that you will analyze.
											<strong>If you set this to 1, you won't be able to set it back to 0.</strong>
										</span>
										<span class="col-md-4 nlp" style="padding-left: 0px;">
											<input type="number" class="form-control" name="unlinked" value="<?= h($study->settings['unlinked']) ?>" min="0" max="1" size="20" />
										</span>
									</td>
									<td>
										<label>Google Sheet</label>
										<span class="help-block">
											<i class="fa fa-info-circle"></i> This ID links to a Google Spreadsheet. You can use one to make it easier to work on spreadsheets collaboratively.
										</span>
										<input type="text" class="form-control" name="google_file_id" value="<?= h($study->settings['google_file_id']) ?>" />
									</td>
								</tr>
								<tr><td colspan="2"><h4>Survey Expiration</h4></td></tr>
								<tr>
									<td colspan="2">
										<label>Inactivity Expiration</label>
										<span class="help-block">
											<i class="fa fa-info-circle"></i> Should the survey expire after a certain number of minutes of inactivity? Specify <b>0 </b>if not. If a user is never active for x minutes or if the last activity is more than x minutes ago, the run will automatically move on.
										</span>
										<div class="form-group col-md-6 nlp" style="padding-left: 0px;">
											<div class="input-group">
												<input type="number" class="form-control" name="expire_after" value="<?= h($study->settings['expire_after']) ?>" min="0" max="3153600" size="20" />
												<div class="input-group-addon"> Minutes</div>
											</div>
										</div>
									</td>
								</tr>
								<tr>
									<td colspan="2">
										<label>Invitation Expiration</label>
										<span class="help-block">
											<i class="fa fa-info-circle"></i>
											If you require the user to have completed this survey after a certain period of time (in minutes) from the invite, specify the number of minutes here.
											If the user reacts almost when this time is about to expire and you want this user to be able to complete filling out the survey, specify a <i>"grace period"</i> below (in minutes).
										</span>
										<div class="form-group " style="padding-left: 0px;">
											<div class="input-group">
												<div class="input-group-addon"> Expire in</div>
												<input type="number" class="form-control" name="expire_invitation_after" value="<?= h($study->settings['expire_invitation_after']) ?>" min="0" max="3153600" size="20" />
												<div class="input-group-addon"> Minutes</div>
												<div class="input-group-addon"> with a grace period of </div>
												<input type="number" class="form-control" name="expire_invitation_grace" value="<?= h($study->settings['expire_invitation_grace']) ?>" min="0" max="3153600" size="20" />
												<div class="input-group-addon"> Minutes</div>
											</div>
											
											<div class="input-group">
												
											</div>
										</div>
									</td>
								</tr>

							</table>


							<div class="clearfix"></div>

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
