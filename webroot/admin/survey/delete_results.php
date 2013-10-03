<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT.'View/admin_header.php';

if(isset($_POST['delete']) AND trim($_POST['delete_confirm']) === $study->name)
{
	if($study->deleteResults()):
		alert("<strong>Success.</strong> All results in '{$study->name}' were deleted.",'alert-success');
	endif;
	redirect_to(WEBROOT."survey/{$study->name}/delete_results");
}
elseif(isset($_POST['delete']))
{
	alert("<b>Error:</b> Study's name must match '{$study->name}' to delete results.",'alert-error');
}

$resultCount = $study->getResultCount();


require_once INCLUDE_ROOT.'View/header.php';

require_once INCLUDE_ROOT.'View/acp_nav.php';
?>
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
<div class="span7">
<form method="post" action="<?=WEBROOT?>admin/survey/<?=$study->name?>/delete_results">
	
	<label>Type the study's name to confirm <br>
		<input name="delete_confirm" title="Confirm" type="text" placeholder="Study-Name"></label>
	
	<input name="delete" class="btn btn-danger hastooltip" title="Delete all results permanently" type="submit" value="Delete <?= ($resultCount['begun']+$resultCount['finished'])?> results">
	
</form>

</div>

<?php
require_once INCLUDE_ROOT.'View/footer.php';
