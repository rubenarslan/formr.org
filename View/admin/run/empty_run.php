<?php
Template::load('header');
Template::load('acp_nav');
?>
<div class="row">
	<div class="col-lg-5 col-md-6 col-sm-8 well">

		<h2><i class="fa fa-eraser"></i> Empty run</h2>
		<form method="post" action="<?=WEBROOT?>admin/run/<?=$run->name?>/empty_run">
			<div class="form-group">
				<label class="control-label" for="empty_confirm" title="this is required to avoid accidental deletions">Type the run's name to confirm that you want to delete all existing <span class="badge badge-success"><?=$users['sessions']?></span> users who progressed on average to position <span class="badge"><?=round($users['avg_position'],2)?></span>.</label>
				<p>You should only use this feature before the study goes live, to get rid of testing remnants! Please backup your survey data individually before emptying a run.</p>
				<div class="controls">
					<div class="input-group">
					  <span class="input-group-addon"><i class="fa fa-pencil-square"></i></span>
			  			<input class="form-control" required name="empty_confirm" id="empty_confirm" type="text" autocomplete="off" placeholder="run name (see up left)"></label>
					</div>
				</div>
			</div>
	
			<div class="form-group small-left">
				<div class="controls">
					<button name="empty" class="btn btn-default btn-danger hastooltip" type="submit"><i class="fa fa-trash-o fa-fw"></i> Empty the entire run permanently</button>
				</div>
			</div>
	
	
		</form>

	</div>
</div>

<?php Template::load('footer');
