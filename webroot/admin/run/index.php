<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "Model/Run.php";

$js = '<script src="'.WEBROOT.'assets/run.js"></script>';

require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
?>
<div class="row">
	<div class="col-md-7 run_dialog">
		<form class="form-horizontal" enctype="multipart/form-data"  id="edit_run" name="edit_run" method="post" action="<?=WEBROOT?>admin/run/<?=$run->name ;?>/" data-units='<?php
			echo json_encode($run->getAllUnitIds());	
			?>'>

			<div class="row">
				<div class="col-md-12 run_dialog">
					<h2>
						<input type="hidden" value="<?=$run->name?>" name="old_run_name" id="run_name">
						<span class="btn-group">
						<a href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_run_cron_toggle" class="btn btn-default run-toggle hastooltip <?=($run->cron_active)?'btn-checked':''?>" title="Turn the run on. If this is not checked, you won't be able to receive email reminders etc. Only turn off for testing.">
							<i class="fa fa-play"></i> Play
						</a>
						<a href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_run_public_toggle" class="btn btn-default run-toggle hastooltip <?=($run->public)?'btn-checked':''?>" title="Make publicly visible and accessible on the front page">
							<i class="fa fa-volume-up"></i> Public
						</a>
						<a href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_run_service_message_toggle" class="btn btn-default run-toggle hastooltip <?=($run->being_serviced)?'btn-checked':''?>" title="Show a service message while you fix the already public run">
							<i class="fa fa-eject"></i> Interrupt
						</a>
					</span>
		
				</h2>
				<h4>
					Api-Secret: <small><?= $run->getApiSecret($user); ?></small>
				</h4>
				<h4>
					Run modules:
				</h4>
				<div class="row">
					<div class="col-md-3">
						<a class="reorder_units btn btn-lg hastooltip" title="Save new positions" href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_reorder">
							<i class="fa fa-exchange fa-rotate-90 fa-larger"></i>
							Reorder
						</a>
					</div>
				</div>
				
				</div>
			</div>
			<div class="row" id="run_dialog_choices">
			  	<div class="form-group span7">
					<div class="btn-group">
						<a class="add_survey add_run_unit btn btn-lg hastooltip" title="Add survey" href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_save_run_unit?type=Survey">
							<i class="fa fa-pencil-square fa-2x"></i>
						</a>
						<a class="add_external add_run_unit  btn btn-lg hastooltip" title="Add external link" href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_save_run_unit?type=External">
							<i class="fa fa-external-link-square fa-2x"></i>
						</a>
						<a class="add_email add_run_unit btn btn-lg hastooltip" title="Add email" href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_save_run_unit?type=Email">
							<i class="fa fa-envelope fa-2x"></i>
						</a>
						<a class="add_skipbackward add_run_unit btn btn-lg hastooltip" title="Add a loop (skip backwards)" href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_save_run_unit?type=SkipBackward">
							<i class="fa fa-backward fa-2x"></i>
						</a>
						<a class="add_pause add_run_unit btn btn-lg hastooltip" title="Add pause" href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_save_run_unit?type=Pause">
							<i class="fa fa-pause fa-2x"></i>
						</a>
						<a class="add_skipforward add_run_unit btn btn-lg hastooltip" title="Add a jump (skip forward)" href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_save_run_unit?type=SkipForward">
							<i class="fa fa-forward fa-2x"></i>
						</a>
						<a class="add_shuffle add_run_unit btn btn-lg hastooltip" title="Add shuffle (randomise participants)" href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_save_run_unit?type=Shuffle">
							<i class="fa fa-random fa-2x"></i>
						</a>
						<a class="add_page add_run_unit btn btn-lg hastooltip" title="Add a stop point" href="<?=WEBROOT?>admin/run/<?=$run->name ;?>/ajax_save_run_unit?type=Page">
							<i class="fa fa-stop fa-2x"></i>
						</a>
					</div>
				</div>
		  	</div>
		</div>


<div class="col-md-5 pull-right well">
<?php
require INCLUDE_ROOT.'View/run_module_explanations.php';	
?>
</div>


<div class="clearfix"></div>

  </form>
</div>
  <?php
  require_once INCLUDE_ROOT . "View/footer.php";
