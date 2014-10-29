<?php

if( !empty($_POST) ) {
	if(isset($_POST['run_name']) AND !preg_match("/^[a-zA-Z][a-zA-Z0-9_]{2,255}$/", $_POST['run_name'])) {
		alert('<strong>Error:</strong> The run name can contain <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the underscore (at least 2 characters, at most 255). It needs to start with a letter.','alert-danger');
		redirect_to("admin/run/");	
	} else {
		$run = new Run($fdb, null, array('run_name' => $_POST['run_name'], 'user_id' => $user->id));
		if($run->valid) {
			alert('<strong>Success.</strong> Run "'.$run->name . '" was created.','alert-success');
			redirect_to("admin/run/{$run->name}");
		}
		else
			alert('<strong>Sorry.</strong> '.implode($run->errors),'alert-danger');
			redirect_to("admin/run/");	
	}
}

Template::load('header');
Template::load('acp_nav');
?>
</div>
<div class="run_header">&nbsp;
</div>	
	<div class="col-lg-10 col-md-10 col-sm-9 main_body">

<div class="row">
	<div class="col-lg-6 col-md-7 col-sm-8 col-lg-offset-1 well">

	<h2><i class="fa fa-rocket"></i> Create a new run</h2>
	<ul class="fa-ul fa-ul-more-padding">
		<li><i class="fa-li fa fa-exclamation-triangle"></i> This is the name that users will see in their browser's address bar for your study, possibly elsewhere too.</li>
		<li><i class="fa-li fa fa-unlock"></i> It can be changed later, but it also changes the link to your study, so don't change it once you're live.</li>
		<li><i class="fa-li fa fa-lightbulb-o"></i> Ideally, it should be the memorable name of your study.</li>
	</ul>

	<form class="" enctype="multipart/form-data"  id="add_study" name="add_study" method="post" action="<?=WEBROOT?>admin/run/add_run">
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
Template::load('footer');