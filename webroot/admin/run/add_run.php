<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "Model/Run.php";

if( !empty($_POST) ) {
	if(isset($_POST['run_name']) AND !preg_match("/^[a-zA-Z][a-zA-Z0-9_]{2,20}$/",$_POST['run_name']))
	{
		alert('<strong>Error:</strong> The run name can only contain a-zA-Z0-9 and the underscore. It needs to start with a letter.','alert-danger');
		redirect_to("admin/run/");	
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
			alert('<strong>Sorry.</strong> '.implode($run->errors),'alert-danger');
	}
}

require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
?>
<div class="row">
	<div class="col-lg-4 col-md-5 col-sm-6 col-lg-offset-1 well">

	<h2><i class="fa fa-rocket"></i> Create a new run</h2>
	<ul class="fa-ul fa-ul-more-padding">
		<li><i class="fa-li fa fa-exclamation-triangle"></i> This is the name that users will see in their browser's address bar for your study, possibly elsewhere too.</li>
		<li><i class="fa-li fa fa-unlock-alt"></i> It cannot be changed later.</li>
		<li><i class="fa-li fa fa-lightbulb-o"></i> Ideally, it should be the memorable name of your study.</li>
	</ul>

	<form class="" enctype="multipart/form-data"  id="add_study" name="add_study" method="post" action="<?=WEBROOT?>admin/run/">
	  	<div class="form-group">
	  		<label class="control-label" for="kurzname">
	  			<?php echo _("Run shorthand:"); ?>
	  		</label>
	  		<div class="controls">
	  			<input class="form-control" required type="text" placeholder="Name (a to Z, 0 to 9 and _)" name="run_name" id="kurzname">
	  		</div>
	  	</div>
	  	<div class="form-group">
	  		<div class="controls">
				<button class="btn btn-default btn-success btn-lg" type="submit"><i class="fa-fw fa fa-rocket"></i> Create run</button>
	  		</div>
	  	</div>
	  </form>
	</div>
</div>

<?php
require_once INCLUDE_ROOT . "View/footer.php";