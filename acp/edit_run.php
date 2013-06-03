<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "admin/admin_header.php";
require_once INCLUDE_ROOT . "Model/Run.php";

$head = '
<link rel="stylesheet" type="text/css" href="'.WEBROOT.'js/vendor/select2/select2.css" />
<script src="'.WEBROOT.'js/run.js"></script>
<script type="text/javascript" src="'.WEBROOT.'js/vendor/select2/select2.js"></script>';

require_once INCLUDE_ROOT . "view_header.php";
require_once INCLUDE_ROOT . "acp/acp_nav.php";
?>
<form class="form-horizontal" enctype="multipart/form-data"  id="edit_run" name="edit_run" method="post" action="<?=WEBROOT?>acp/<?=$run->name ;?>" data-units='<?php
	echo json_encode($run->getAllUnitIds());	
	?>'>
<div class="span10 run_dialog">
	
	<h2 class="row" id="run_dialog_heading">
		<?php echo __("%s <small>run</small>" , $run->name); ?>
		<input type="hidden" value="<?=$run->name?>" name="old_run_name" id="run_name">
		<span class="btn-group">
			<a href="<?=WEBROOT?>acp/<?=$run->name ;?>/ajax_run_cron_toggle" class="btn btn-mini run-toggle hastooltip <?=($run->cron_active)?'btn-checked':''?>" title="Turn CronJob on (automatic progress checks etc)">Cron</a>
			<a href="<?=WEBROOT?>acp/<?=$run->name ;?>/ajax_run_public_toggle" class="btn btn-mini run-toggle hastooltip <?=($run->public)?'btn-checked':''?>" title="Make publicly visible on the front page">Public</a>
		</span>
	</h2>
	<h4>
		Api-Secret: <small><?= $run->getApiSecret($user); ?></small>
	</h4>
	<p>&nbsp;</p>
	<div class="row" id="run_dialog_choices">
		<div class="span2">
			<a class="reorder_units btn btn-large hastooltip" title="Save new positions" href="<?=WEBROOT?>acp/<?=$run->name ;?>/ajax_reorder">
				<i class="icon-exchange icon-rotate-90 icon-larger"></i>
			</a>
		</div>
	  	<div class="control-group span7">
			<div class="btn-group">
				<a class="add_survey add_run_unit btn btn-large hastooltip" title="Add survey" href="<?=WEBROOT?>acp/<?=$run->name ;?>/ajax_save_run_unit?type=Survey">
					<i class="icon-question icon-2x"></i>
				</a>
				<a class="add_branch add_run_unit btn btn-large hastooltip" title="Add branch" href="<?=WEBROOT?>acp/<?=$run->name ;?>/ajax_save_run_unit?type=Branch">
					<i class="icon-code-fork icon-2x icon-flip-vertical"></i>
				</a>
				<a class="add_pause add_run_unit btn btn-large hastooltip" title="Add pause" href="<?=WEBROOT?>acp/<?=$run->name ;?>/ajax_save_run_unit?type=Pause">
					<i class="icon-time icon-2x"></i>
				</a>
				<a class="add_pause add_run_unit btn btn-large hastooltip" title="Add time-branch" href="<?=WEBROOT?>acp/<?=$run->name ;?>/ajax_save_run_unit?type=TimeBranch">
					<i class="icon-fast-forward icon-2x"></i>
				</a>
				
				<a class="add_external add_run_unit  btn btn-large hastooltip" title="Add external link" href="<?=WEBROOT?>acp/<?=$run->name ;?>/ajax_save_run_unit?type=External">
					<i class="icon-external-link icon-2x"></i>
				</a>
				<a class="add_email add_run_unit btn btn-large hastooltip" title="Add email" href="<?=WEBROOT?>acp/<?=$run->name ;?>/ajax_save_run_unit?type=Email">
					<i class="icon-envelope icon-2x"></i>
				</a>
				<a class="add_page add_run_unit btn btn-large hastooltip" title="Add feedback page" href="<?=WEBROOT?>acp/<?=$run->name ;?>/ajax_save_run_unit?type=Page">
					<i class="icon-bar-chart icon-2x"></i>
				</a>
			</div>
		</div>
  	</div>
</div>
<div class="clearfix"></div>
	
  </form>

  <?php
  require_once INCLUDE_ROOT . "view_footer.php";
