<?php
if(isset($_POST['delete']) AND trim($_POST['delete_confirm']) === $study->name)
{
	$study->delete();
	alert("<strong>Success.</strong> Successfully deleted study '{$study->name}'.",'alert-success');
	redirect_to(WEBROOT."admin/index");
}
elseif(isset($_POST['delete']))
{
	alert("<b>Error:</b> You must type the study's name '{$study->name}' to delete it.",'alert-danger');
}

$resultCount = $study->getResultCount();


Template::load('header');
Template::load('acp_nav');
?>
<div class="row">
	<div class="col-lg-5 col-md-6 col-sm-8 well">

		<h2>Delete study <small>with <?=($resultCount['begun']+$resultCount['finished'])?> result rows</small></h2>
		<?php
		if(isset($msg)) echo '<div class="alert '.$alertclass.' span6">'.$msg.'</div>';
		?>
		<form method="post" action="<?=WEBROOT?>admin/survey/<?=$study->name?>/delete_study">
			<div class="form-group">
				<label class="control-label" for="delete_confirm" title="this is required to avoid accidental deletions">Type the study's name to confirm its deletion:</label>
				<div class="controls">
					<div class="input-group">
					  <span class="input-group-addon"><i class="fa fa-pencil-square"></i></span>
			  			<input class="form-control" required name="delete_confirm" id="delete_confirm" type="text" autocomplete="off" placeholder="survey name (see up left)"></label>
					</div>
				</div>
			</div>
	
			<div class="form-group small-left">
				<div class="controls">
					<button name="delete" class="btn btn-default btn-danger hastooltip" type="submit"><i class="fa fa-trash-o fa-fw"></i> Delete the entire study permanently (<?=($resultCount['begun']+$resultCount['finished'])?> result rows)</button>
				</div>
			</div>
	
	
		</form>

	</div>
</div>

<?php
Template::load('footer');
