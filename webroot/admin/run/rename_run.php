<?php

if( !empty($_POST) ) {
	if(isset($_POST['new_name']))
	{
		if($run->rename($_POST['new_name']))
		{
			alert('<strong>Success.</strong> The run was renamed to "'.$_POST['new_name'] . '"','alert-success');
			redirect_to("admin/run/".$_POST['new_name']);
		}
		else
		{
			alert('<strong>Sorry, run could not be renamed.</strong> '.implode($run->errors),'alert-danger');
		}
	}
}

Template::load('header');
Template::load('acp_nav');
?>
<div class="row">
	<div class="col-lg-4 col-md-5 col-sm-6 col-lg-offset-1 well">

	<h2><i class="fa fa-unlock"></i> Rename run</h2>
	<ul class="fa-ul fa-ul-more-padding">
		<li><i class="fa-li fa fa-exclamation-triangle"></i> This is the name that users will see in their browser's address bar for your study, possibly elsewhere too.</li>
		<li><i class="fa-li fa fa-unlock"></i> It can be changed later, but it also changes the link to your study, so don't change it once you're live.</li>
		<li><i class="fa-li fa fa-lightbulb-o"></i> Ideally, it should be the memorable name of your study.</li>
	</ul>

	<form class="" enctype="multipart/form-data"  id="rename_run" name="rename_run" method="post" action="<?=WEBROOT?>admin/run/<?=$run->name;?>/rename_run">
	  	<div class="form-group">
	  		<label class="control-label" for="kurzname">
	  			<?php echo _("Run shorthand:"); ?>
	  		</label>
	  		<div class="controls">
	  			<input class="form-control" required type="text" placeholder="Name (a to Z, 0 to 9 and _)" name="new_name" id="kurzname" value="<?=$run->name;?>">
	  		</div>
	  	</div>
	  	<div class="form-group">
	  		<div class="controls">
				<button class="btn btn-default btn-success btn-lg" type="submit"><i class="fa-fw fa fa-unlock"></i> Rename run</button>
	  		</div>
	  	</div>
	  </form>
	</div>
</div>

<?php Template::load('footer');