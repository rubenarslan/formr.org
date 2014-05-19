<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "Model/Run.php";

$js = '<script src="'.WEBROOT.'assets/run_settings.js"></script>
<script src="'.WEBROOT.'assets/run.js"></script>';
$service_message_id = $run->getServiceMessageId();
$reminder_email_id = $run->getReminderId();
//$overview_script_id = $run->getOverviewScriptId();
$overview_script_id = $run->getServiceMessageId();

require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
?>
<div class="row">
	
	<div class="col-md-7 run_dialog">
	
		<ul class="nav nav-tabs">
		  <li class="active"><a href="#settings" data-toggle="tab">Settings</a></li>
		  <li><a href="#css" data-toggle="tab">CSS</a></li>
		  <li><a href="#js" data-toggle="tab">JS</a></li>
		  <li><a href="#service_message" data-toggle="tab">Service message</a></li>
		  <li><a href="#reminder" data-toggle="tab">Reminder</a></li>
		  <li><a href="#overview_script" data-toggle="tab">Overview</a></li>
		</ul>
	
		<div class="tab-content">
			<div class="tab-pane fade in active" id="settings">
				<form class="form-horizontal" enctype="multipart/form-data"  id="run_settings" method="post" action="<?=WEBROOT?>admin/run/<?=$run->name ;?>/settings">
					
				<div class="row">
					<h3 class="col-lg-12"><i class="fa fa-cogs"></i> Settings</h3>
				
					<h4 class="col-lg-12">
						Api-Secret: <small><?= $run->getApiSecret($user); ?></small>
					</h3>
			
					<label class="col-lg-12"> <span title="Will be shown on every page of the run">Title</span>:
					<input type="text" maxlength="1000" placeholder="Title" name="Title" class="form-control">
					</label>
			
					<label class="col-lg-12"> <span title="Link to your header image, shown on every run page">Header image</span>:
					<input type="text" maxlength="255" placeholder="Title" name="header_image_path" class="form-control">
					</label>

					<label class="col-lg-12"> <span title="Will be shown on every page of the run">Description</span>:
					<textarea data-editor="markdown" placeholder="Description" name="description" rows="10" cols="80" class="big_ace_editor form-control"></textarea>
					</label>

					<label class="col-lg-12"> <span title="Will be shown on every page of the run, good for contact info">Footer text</span>:
					<textarea data-editor="markdown" placeholder="Footer text" name="footer_text" rows="10" cols="80" class="big_ace_editor form-control"></textarea>
					</label>
			
					<label class="col-lg-12"> <span title="This will be the description of your study shown on the public page">Public blurb</span>:
					<textarea data-editor="markdown" placeholder="Blurb" name="public_blurb" rows="10" cols="80" class="big_ace_editor form-control"></textarea>
					</label>
				</div>
				</form>
				
			</div>
			<div class="tab-pane fade" id="css">
				<h3><i class="fa fa-css3"></i> Cascading style sheets</h3>
				<textarea data-editor="css" placeholder="Enter your custom CSS here" name="custom_css" rows="40" cols="80" class="big_ace_editor form-control"></textarea>
			</div>
			<div class="tab-pane fade" id="js">
				<h3>Javascript</h3>
				<textarea data-editor="javascript" placeholder="Enter your custom JS here" name="custom_js" rows="40" cols="80" class="big_ace_editor form-control"></textarea>
			</div>
			<div class="tab-pane fade" id="service_message">
				<div class="row">

	
					<div class="single_unit_display">
						<form class="form-horizontal edit_run" enctype="multipart/form-data"  name="edit_run" method="post" action="<?=WEBROOT?>admin/run/<?=$run->name ;?>" data-units='<?php
							echo json_encode(array(array("service_message" => true,"run_unit_id" => $service_message_id) ) );
							?>'>
							<h3><i class="fa fa-eject"></i> Edit service message</h3>
							<ul class="fa-ul fa-ul-more-padding">
								<li><i class="fa-li fa fa-cog fa-lg fa-spin"></i> If you are making changes to your run, while it's live, you may want to keep your users from using it at the time. <br>Use this message to let them know that the run will be working again soon.</li>
								<li><i class="fa-li fa fa-lg fa-stop"></i> You can also use this message to end a study, so that no new users will be admitted and old users who are not finished cannot go on.</li>
							</ul>
							<div class="run_units">
							</div>
						</form>

					</div>
	
				</div>
				
			</div>
			<div class="tab-pane fade" id="reminder">
				<div class="row">
					<div class="single_unit_display">
						<form class="form-horizontal edit_run" enctype="multipart/form-data" name="edit_run" method="post" action="<?=WEBROOT?>admin/run/<?=$run->name ;?>" data-units='<?php
							echo json_encode(array(array("run_unit_id" => $reminder_email_id) ) );
							?>'>
							<h3><i class="fa fa-bullhorn"></i> Edit email reminder</h3>
							<p class="lead">
								Modify the text of a reminder, which you can then send to any user using the <i class="fa fa-bullhorn"></i> reminder button in the <a href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/user_overview">user overview</a>.
							</p>
							<div class="run_units">
							</div>
						</form>
					</div>
				</div>
			</div>
			<div class="tab-pane fade" id="overview_script">
				<div class="row">
					<div class="single_unit_display">
						<form class="form-horizontal edit_run" enctype="multipart/form-data" name="edit_run" method="post" action="<?=WEBROOT?>admin/run/<?=$run->name ;?>" data-units='<?php
							echo json_encode(array(array("overview_script" => true,"run_unit_id" => $overview_script_id) ) );
							?>'>
							<h3><i class="fa fa-eye"></i> Edit overview script</h3>
							<ul class="fa-ul fa-ul-more-padding">
								<li><i class="fa-li fa fa-code"></i> In here, you can use Markdown and R interspersed to make a custom overview for your study.</li>
								<li><i class="fa-li fa fa-lg fa-thumb-tack"></i> Useful commands to start might be <pre><code class="r">nrow(survey_name) # get the number of entries
table(is.na(survey_name$ended)) # get finished/unfinished entries
library(ggplot2)
qplot(survey_name$created) # plot entries by startdate</code></pre></li>
							</ul>
							<div class="run_units">
							</div>
						</form>
					</div>
				</div>
			</div>
		</div>
	</div>


	<div class="col-md-5 pull-right well transparent_well">
<?php
require INCLUDE_ROOT.'View/run_settings_help.php';	
?>
	</div>


	<div class="clearfix"></div>
</div>
<?php
  require_once INCLUDE_ROOT . "View/footer.php";
