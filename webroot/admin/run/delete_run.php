<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT.'View/admin_header.php';

if(isset($_POST['delete']) AND trim($_POST['delete_confirm']) === $run->name)
{
	$run->delete();
}
elseif(isset($_POST['delete']))
{
	alert("<b>Error:</b> You must type the run's name '{$run->name}' to delete it.",'alert-danger');
}

$users = $run->getNumberOfSessionsInRun();

require_once INCLUDE_ROOT.'View/header.php';

require_once INCLUDE_ROOT.'View/acp_nav.php';
?>
<div class="row">
	<div class="col-lg-5 col-md-6 col-sm-8 well">

		<h2><i class="fa fa-trash-o"></i> Delete run</h2>
		<form method="post" action="<?=WEBROOT?>admin/run/<?=$run->name?>/delete_run">
			<div class="form-group">
				<label class="control-label" for="delete_confirm" title="this is required to avoid accidental deletions">Type the run's name to confirm that you want delete all existing <span class="badge badge-success"><?=$users['sessions']?></span> users who progressed on average to position <span class="badge"><?=round($users['avg_position'],2)?></span>:</label>
				<div class="controls">
					<div class="input-group">
					  <span class="input-group-addon"><i class="fa fa-pencil-square"></i></span>
			  			<input class="form-control" required name="delete_confirm" id="delete_confirm" type="text" autocomplete="off" placeholder="run name (see up left)"></label>
					</div>
				</div>
			</div>
	
			<div class="form-group small-left">
				<div class="controls">
					<button name="delete" class="btn btn-default btn-danger hastooltip" type="submit"><i class="fa fa-trash-o fa-fw"></i> Delete the entire run permanently</button>
				</div>
			</div>
	
	
		</form>

	</div>
</div>

<?php
require_once INCLUDE_ROOT.'View/footer.php';
