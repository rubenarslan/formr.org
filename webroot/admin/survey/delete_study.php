<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT.'View/admin_header.php';

if(isset($_POST['delete']) AND trim($_POST['delete_confirm']) === $study->name)
{
	$study->delete();
	alert("<strong>Success.</strong> Successfully deleted study '{$study->name}'.",'alert-success');
	redirect_to(WEBROOT.">admin/survey/index");
}
elseif(isset($_POST['delete']))
{
	alert("<b>Error:</b> You must type the study's name '{$study->name}' to delete it.",'alert-error');
}

$resultCount = $study->getResultCount();


require_once INCLUDE_ROOT.'View/header.php';

require_once INCLUDE_ROOT.'View/acp_nav.php';
?>
<h2>Delete study <small>has <?=($resultCount['begun']+$resultCount['finished'])?> result rows</small></h2>
<?php
if(isset($msg)) echo '<div class="alert '.$alertclass.' span6">'.$msg.'</div>';
?>
<div class="span7">
<form method="post" action="<?=WEBROOT?>admin/survey/<?=$study->name?>/delete_study">
	
	<label>Type the study's name to confirm its deletion<br>
		<input name="delete_confirm" title="Confirm" type="text" placeholder="Study-Name"></label>
	
	<input name="delete" class="btn btn-danger hastooltip" title="Delete the entire study permanently" type="submit" value="Delete the entire study permanently (<?=($resultCount['begun']+$resultCount['finished'])?> result rows)">
	
</form>

</div>

<?php
require_once INCLUDE_ROOT.'View/footer.php';
