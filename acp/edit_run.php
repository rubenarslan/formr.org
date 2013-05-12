<?php
// todo: add each step should display how many people have reached it
require_once '../define_root.php';
require_once INCLUDE_ROOT . "admin/admin_header.php";
require_once INCLUDE_ROOT . "Model/Run.php";

$run = new Run($fdb, $_GET['run_name']);

if( !empty($_POST) ) {
	if($run->valid)
	{
		alert('<strong>Success.</strong> Run "'.$run->name . '" was created.','alert-success');
		redirect_to(WEBROOT . "acp/{$run->name}");
	}
	else
		alert('<strong>Sorry.</strong> '.implode($run->errors),'alert-error');
}

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
<div class="span9 run_dialog">
	<h2 class="row" id="run_dialog_heading">
		
	  	<div class="control-group" style="font:inherit;line-height:inherit">
	  			<?php echo __("%s <small>run</small>" , $run->name); ?>
	  			<input type="hidden" value="<?=$run->name?>" name="old_run_name" id="run_name">
	  	</div>
	</h2>

	<div class="row" id="run_dialog_choices">
		<div class="span2">
			<a class="add_survey add_run_unit btn btn-large hastooltip" title="Save new positions" href="<?=WEBROOT?>acp/<?=$run->name ;?>/ajax_reorder">
				<i class="icon-exchange icon-rotate-90 icon-larger"></i>
			</a>
		</div>
	  	<div class="control-group span7">
			<div class="btn-group">
				<a class="add_survey add_run_unit btn btn-large hastooltip" title="Add survey" href="<?=WEBROOT?>acp/<?=$run->name ;?>/ajax_add_survey">
					<i class="icon-question icon-2x"></i>
				</a>
				<a class="add_branch add_run_unit btn btn-large hastooltip" title="Add branch" href="<?=WEBROOT?>acp/<?=$run->name ;?>/ajax_add_branch">
					<i class="icon-code-fork icon-2x icon-flip-vertical"></i>
				</a>
				<a class="add_break add_run_unit btn btn-large hastooltip" title="Add break">
					<i class="icon-time icon-2x"></i>
				</a>
				<a class="add_external add_run_unit  btn btn-large hastooltip" title="Add external link">
					<i class="icon-external-link icon-2x"></i>
				</a>
				<a class="add_email add_run_unit btn btn-large hastooltip" title="Add email">
					<i class="icon-envelope icon-2x"></i>
				</a>
				<a class="add_page add_run_unit btn btn-large hastooltip" title="Add feedback page">
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
