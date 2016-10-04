<?php
$js = '<script src="' . asset_url('assets/' . (DEBUG ? 'js' : 'minified') . '/run_settings.js') . '"></script>';
$js.= '<script src="' . asset_url('assets/' . (DEBUG ? 'js' : 'minified') . '/run.js') . '"></script>';

Template::load('header', array('js' => $js));
Template::load('acp_nav');
?>
<div class="row">

	<div class="col-md-10 transparent_well settings" style="padding-bottom: 20px;">
		<h2><i class="fa fa-cogs"></i> Settings</h3>
		<p>Run ID: <?php echo $run_id; ?></p>

			<ul class="nav nav-tabs">
				<li class="active"><a href="#settings" data-toggle="tab">Settings</a></li>
				<li><a href="#css" data-toggle="tab">CSS</a></li>
				<li><a href="#js" data-toggle="tab">JS</a></li>
				<li><a href="#service_message" data-toggle="tab">Service message</a></li>
				<li><a href="#reminder" data-toggle="tab">Reminder</a></li>
				<li><a href="#overview_script" data-toggle="tab">Overview</a></li>
				<li><a href="#osf" data-toggle="tab">OSF</a></li>
			</ul>

			<div class="tab-content">
				<div class="tab-pane fade in active" id="settings">
					<form class="form-horizontal" enctype="multipart/form-data"  id="run_settings" method="post" action="<?php echo admin_run_url($run->name, 'ajax_save_settings'); ?>">

						<div class="row">
							<h3 class="col-lg-12">general settings</h3>

							<p class="pull-right" style="padding-top:10px;margin-bottom:0;margin-right:15px">
								<input type="submit" name="submit_settings" value="Save" class="btn btn-lg save_settings">
							</p>
							<p class="col-lg-10">
								Here you can set a couple of simple general settings for your study. Make sure that you provide an email address to contact you somewhere (usually in the footer).
							</p>

							<h4 class="col-lg-12">
								Api-Secret: <small><?= $run->getApiSecret($user); ?></small>
								</h3>

								<label class="col-lg-12"> <span title="Will be shown on every page of the run">Title</span>:
									<input type="text" maxlength="1000" placeholder="Title" name="title" class="form-control" value="<?= h($run->title); ?>">
								</label>

								<label class="col-lg-12"> <span title="Link to your header image, shown on every run page">Header image</span>:
									<input type="text" maxlength="255" placeholder="URL" name="header_image_path" class="form-control" value="<?= h($run->header_image_path); ?>">
								</label>


								<label class="col-lg-12"> <span title="">Cron Job</span>:<br>
									Enable automatic sending of email invitations etc. You would want to turn this off only in case of unforeseen problems (e.g. you're spamming the users by accident). <input type="hidden" name="cron_active" value="0"><input type="checkbox" name="cron_active" <?= ($run->cron_active) ? 'checked' : '' ?> value="1">
								</label>

								<label class="col-lg-12"> <span title="Will be shown on every page of the run">Description</span>:
									<textarea data-editor="markdown" placeholder="Description" name="description" rows="10" cols="80" class="big_ace_editor form-control"><?= h($run->description); ?></textarea>
								</label>

								<label class="col-lg-12"> <span title="Will be shown on every page of the run, good for contact info">Footer text</span>:
									<textarea data-editor="markdown" placeholder="Footer text" name="footer_text" rows="10" cols="80" class="big_ace_editor form-control"><?= h($run->footer_text); ?></textarea>
								</label>

								<label class="col-lg-12"> <span title="This will be the description of your study shown on the public page">Public blurb</span>:
									<textarea data-editor="markdown" placeholder="Blurb" name="public_blurb" rows="10" cols="80" class="big_ace_editor form-control"><?= h($run->public_blurb); ?></textarea>
								</label>




						</div>
					</form>

				</div>
				<div class="tab-pane fade" id="css">
					<form class="form-horizontal" enctype="multipart/form-data"  id="run_settings" method="post" action="<?php echo admin_run_url($run->name, 'ajax_save_settings'); ?>">

						<div class="row">
							<p class="pull-right" style="padding-top:10px;margin-bottom:0;margin-right:15px">
								<input type="submit" name="submit_settings" value="Save" class="btn btn-lg save_settings">
							</p>
							<h3 class="col-lg-7"><i class="fa fa-css3"></i> Cascading style sheets</h3>
							<p class="col-lg-12">
								CSS allows you to apply custom styles to every page of your study. If you want to limit styles to
								certain pages, you can use CSS classes referring to either position in the run (e.g. <code class="css">.run_position_10 {}</code>) or module type (e.g. <code class="css">.run_unit_type_Survey {}</code>). Learn about <a href="http://docs.webplatform.org/wiki/guides/getting_started_with_css">CSS at Webplatform.org</a>.
							</p>

							<textarea data-editor="css" placeholder="Enter your custom CSS here" name="custom_css" rows="40" cols="80" class="big_ace_editor form-control"><?= h($run->getCustomCSS()); ?></textarea>
						</div>
					</form>
				</div>
				<div class="tab-pane fade" id="js">
					<form class="form-horizontal" enctype="multipart/form-data"  id="run_settings" method="post" action="<?php echo admin_run_url($run->name, 'ajax_save_settings'); ?>">

						<div class="row">
							<p class="pull-right" style="padding-top:10px;margin-bottom:0;margin-right:15px">
								<input type="submit" name="submit_settings" value="Save" class="btn btn-lg save_settings">
							</p>
							<h3 class="col-lg-7">Javascript</h3>
							<p class="col-lg-12">
								Javascript allows you to apply custom scripts to every page of your study. This is a fully-fledged programming language. You can use it to make things move, give dynamic hints to the user and so on. Learn about <a href="http://www.codecademy.com/tracks/javascript">JS at Codecademy.com</a>.
							</p>
							<textarea data-editor="javascript" placeholder="Enter your custom JS here" name="custom_js" rows="40" cols="80" class="big_ace_editor form-control"><?= h($run->getCustomJS()); ?></textarea>
						</div>
					</form>
				</div>
				<div class="tab-pane fade" id="service_message">
					<div class="row">
						<div class="add">
							<h3><i class="fa fa-eject"></i> Edit service message</h3>
							<ul class="fa-ul fa-ul-more-padding">
								<li><i class="fa-li fa fa-cog fa-lg fa-spin"></i> If you are making changes to your run, while it's live, you may want to keep your users from using it at the time. <br>Use this message to let them know that the run will be working again soon.</li>
								<li><i class="fa-li fa fa-lg fa-stop"></i> You can also use this message to end a study, so that no new users will be admitted and old users who are not finished cannot go on.</li>
							</ul>
							<?php if (empty($service_messages)): ?>
								<a href="<?= admin_run_url($run->name, 'create_run_unit?type=Page&special=ServiceMessagePage&redirect=settings:::service_message') ?>" class="btn btn-default pull-right add_run_unit"><i class="fa fa-plus"></i> Add Service Message</a>
							<?php endif; ?>
						</div>
						<div class="clearfix"></div>
						<div class="row special-units  reminder-cells">
							<?php foreach ($service_messages as $message): ?>
								<div class="col-md-11 single_unit_display">
									<form class="form-horizontal edit_run" enctype="multipart/form-data" name="edit_run" method="post" action="<?php echo admin_run_url($run->name); ?>" data-units='<?php echo json_encode($message['html_units']); ?>'>
										<div class="run_units"></div>
									</form>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
				<div class="tab-pane fade" id="reminder">
					<div class="row">
						<div class="add">
							<h3><i class="fa fa-bullhorn"></i> Add/Modify Email Reminders</h3>
							<p class="lead">
								Modify the text of a reminder, which you can then send to any user using the <i class="fa fa-bullhorn"></i> reminder button in the <a href="<?php echo admin_run_url($run->name, 'user_overview'); ?>">user overview</a>.
							</p>
							<a href="<?= admin_run_url($run->name, 'create_run_unit?type=Email&special=ReminderEmail&redirect=settings:::reminder') ?>" class="btn btn-default pull-right add_run_unit"><i class="fa fa-plus"></i> Add Reminder</a>
						</div>
						<div class="clearfix"></div>
						<div class="row special-units  reminder-cells">
							<?php foreach ($reminders as $reminder): ?>
								<div class="col-md-6 single_unit_display">
									<form class="form-horizontal edit_run" enctype="multipart/form-data" name="edit_run" method="post" action="<?php echo admin_run_url($run->name); ?>" data-units='<?php echo json_encode($reminder['html_units']); ?>'>
						
										<a href="<?= admin_run_url($run->name, 'delete_run_unit?type=Email&special=ReminderEmail&redirect=settings:::reminder&unit_id=' . $reminder['id']) ?>" class="reminder-delete remove_unit_from_run" data-action="<?php echo admin_run_url($run->name); ?>" data-id="<?php echo $reminder['id']; ?>"><i class="fa fa-2x fa-trash"></i></a>
										<div class="run_units"></div>
									</form>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
				<div class="tab-pane fade" id="overview_script">
					<div class="row">
						<div class="add">
							<h3><i class="fa fa-eye"></i> Edit overview script</h3>
							<ul class="fa-ul fa-ul-more-padding">
								<li><i class="fa-li fa fa-code"></i> In here, you can use Markdown and R interspersed to make a custom overview for your study.</li>
								<li><i class="fa-li fa fa-lg fa-thumb-tack"></i> Useful commands to start might be <pre><code class="r">nrow(survey_name) # get the number of entries
table(is.na(survey_name$ended)) # get finished/unfinished entries
table(is.na(survey_name$modified)) # get entries where any data was entered vs not
library(ggplot2)
qplot(survey_name$created) # plot entries by startdate</code></pre></li>
							</ul>
							<?php if (empty($overview_scripts)): ?>
								<a href="<?= admin_run_url($run->name, 'create_run_unit?type=Page&special=OverviewScriptPage&redirect=settings:::overview_script') ?>" class="btn btn-default pull-right add_run_unit"><i class="fa fa-plus"></i> Add Overview Script</a>
							<?php endif; ?>
						</div>
						<div class="clearfix"></div>
						<div class="row special-units  reminder-cells">
							<?php foreach ($overview_scripts as $script): ?>
								<div class="col-md-11 single_unit_display">
									<form class="form-horizontal edit_run" enctype="multipart/form-data" name="edit_run" method="post" action="<?php echo admin_run_url($run->name); ?>" data-units='<?php echo json_encode($script['html_units']); ?>'>
										<div class="run_units"></div>
									</form>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<div class="tab-pane fade" id="osf">
					<div class="row">
						<div class="single_unit_display">
							<?php if (empty($osf_token)): ?>
								<p>
									<br /><br />
									<a href="<?php echo site_url('osf-api/login?redirect=admin/run/' . $run->name . '/settings'); ?>" class="btn btn-default">Connect to the Open Science Framework</a>
								</p>
							<?php else: ?>
								<br /><br />
								<div class="panel panel-default" id="panel1">
									<div class="panel-heading">
										<h4 class="panel-title">
											<a data-toggle="collapse" data-target="#collapseOne"  href="#collapseOne"><i class="fa fa-cloud-upload"></i> Export run structure to OSF project </a>
										</h4>
									</div>
									<div id="collapseOne" class="panel-collapse collapse in">
										<div class="panel-body">
											<form action="<?php echo admin_url('osf'); ?>" method="post" />
											<table class="table table-responsive">
												<thead>
													<tr>
														<th>Select OSF Project</th>
														<th>&nbsp;</th>
													</tr>
												</thead>
												<tbody>
													<tr>
														<td>
															<div class="input-group">
																<span class="input-group-addon"><i class="fa fa-rocket"></i></span>
																<div class="form-group">
																	<select name="osf_project" class="form-control">
																		<option value="">....</option>
																		<?php foreach ($osf_projects as $project): 
																			$selected = $project['id'] == $osf_project ? 'selected="selected"' : null ?>
																			<option value="<?= $project['id']; ?>" <?= $selected ?>><?= $project['name']; ?> </option>
																		<?php endforeach; ?>
																	</select>          
																</div>
															</div>
															<p>
																<a href="https://osf.io/dashboard/" target="_blank">Create an OSF project</a>
															</p>
														</td>
														<td class="col-md-5">
															<input type="hidden" name="formr_project" value="<?php echo $run->name;?>" />
															<input type="hidden" name="osf_action" value="export-run" />
															<input type="hidden" name="redirect" value="admin/run/<?= $run->name ?>/settings#osf" />
															<button type="submit" class="btn btn-primary btn-large"><i class="fa fa-mail-forward"></i> Export</button>
														</td>
													</tr>
												</tbody>
											</table>
											</form>
										</div>	
									</div>
								</div>
							<?php endif; ?>
						</div>
					</div>
				</div>

			</div>
	</div>



	<div class="clearfix"></div>
</div>
<?php
Template::load('admin/run/run_modals', array('reminders' => array()));
Template::load('footer');
