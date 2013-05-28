<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "admin/admin_header.php";
require_once INCLUDE_ROOT . "Model/Run.php";

if( !empty($_POST) ) {
	$run = new Run($fdb, null, array('run_name' => $_POST['run_name'], 'user_id' => $user->id));
	if($run->valid)
	{
		alert('<strong>Success.</strong> Run "'.$run->name . '" was created.','alert-success');
		redirect_to(WEBROOT . "acp/{$run->name}");
	}
	else
		alert('<strong>Sorry.</strong> '.implode($run->errors),'alert-error');
}

require_once INCLUDE_ROOT . "view_header.php";
require_once INCLUDE_ROOT . "acp/acp_nav.php";
?>
<form class="form-horizontal" enctype="multipart/form-data"  id="add_study" name="add_study" method="post" action="<?=WEBROOT?>acp/add_run">
  	<div class="control-group">
  		<label class="control-label" for="kurzname">
  			<?php echo _("Run Kurzname<br>(wird für URL benutzt):"); ?>
  		</label>
  		<div class="controls">
  			<input required type="text" placeholder="Name (a-Z0-9_)" name="run_name" id="kurzname">
  		</div>
  	</div>
  	<div class="control-group">
  		<div class="controls">
  			<input class="btn btn-success" type="submit" value="<?php echo _("Run anlegen"); ?>">
  		</div>
  	</div>
  </form>

<?php
require_once INCLUDE_ROOT . "view_footer.php";