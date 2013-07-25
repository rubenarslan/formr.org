<?php
require_once '../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "Model/Run.php";

if( !empty($_POST) ) {
	if(isset($_POST['run_name']) AND !preg_match("/^[a-zA-Z][a-zA-Z0-9_]{2,20}$/",$_POST['run_name']))
	{
		alert('<strong>Error:</strong> The run name can only contain a-zA-Z0-9 and the underscore. It needs to start with a letter.','alert-error');
		redirect_to("admin/add_run");	
	}
	else
	{
		$run = new Run($fdb, null, array('run_name' => $_POST['run_name'], 'user_id' => $user->id));
		if($run->valid)
		{
			alert('<strong>Success.</strong> Run "'.$run->name . '" was created.','alert-success');
			redirect_to("admin/run/{$run->name}");
		}
		else
			alert('<strong>Sorry.</strong> '.implode($run->errors),'alert-error');
	}
}

require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
?>
<form class="form-horizontal" enctype="multipart/form-data"  id="add_study" name="add_study" method="post" action="<?=WEBROOT?>admin/add_run">
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
require_once INCLUDE_ROOT . "View/footer.php";