<?php
if(isset($_POST['delete']) AND trim($_POST['delete_confirm']) === $study->name)
{
	if($study->deleteResults()):
		alert("<strong>Success.</strong> All results in '{$study->name}' were deleted.",'alert-success');
	endif;
	redirect_to(WEBROOT."admin/survey/{$study->name}/delete_results");
}
elseif(isset($_POST['delete']))
{
	alert("<b>Error:</b> Survey's name must match '{$study->name}' to delete results.",'alert-danger');
}

$resultCount = $study->getResultCount();


Template::load('header');
Template::load('acp_nav');
?>
<div class="row">
	<div class="col-lg-5 col-md-6 col-sm-8 well">

	<h2>Results <small>
			<?=(int)$resultCount['finished']?> complete,
			<?=(int)$resultCount['begun']?> begun
	</small></h2>
	<?php
	if(isset($msg)) echo '<div class="alert '.$alertclass.' span6">'.$msg.'</div>';

	if((int)$resultCount['finished'] > 10)
		echo '<div class="alert alert-warning span6">
			<h3>Warning!</h3>
	Please <a href="'.WEBROOT.'survey/'.$study->name.'/show_results">review the existing results</a> before deleting them.</div>';
	?>
	<form method="post" action="<?=WEBROOT?>admin/survey/<?=$study->name?>/delete_results">
		<div class="form-group">
			<label class="control-label" for="delete_confirm" title="this is required to avoid accidental deletions">Type the study's name to confirm deletion of results:</label>
			<div class="controls">
				<div class="input-group">
				  <span class="input-group-addon"><i class="fa fa-pencil-square"></i></span>
		  			<input class="form-control" required name="delete_confirm" id="delete_confirm" type="text" placeholder="survey name (see up left)"></label>
				</div>
			</div>
		</div>
	
		<div class="form-group small-left">
			<div class="controls">
				<button name="delete" class="btn btn-default btn-danger hastooltip" title="Delete all results permanently" type="submit"><i class="fa fa-eraser fa-fw"></i> Delete <?= ($resultCount['begun']+$resultCount['finished'])?> results</button>
			</div>
		</div>
	
	
	</form>

	</div>
</div>

<?php
Template::load('footer');
