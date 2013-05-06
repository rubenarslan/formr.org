<?php
require_once "../includes/define_root.php";
require_once INCLUDE_ROOT.'admin/admin_header.php';

if(isset($_POST['delete']) AND trim($_POST['delete_confirm']) === $study->name)
{
	$study->delete();
	redirect_to(WEBROOT."acp/acp.php?msg=Successfully+deleted+{$study->name}");
}
elseif(isset($_POST['delete']))
{
	$msg = "<b>Error:</b> You must type the study's name '{$study->name}' to delete it.";
	$alertclass = 'alert-error';
}

$resultCount = $study->getResultCount();


require_once INCLUDE_ROOT.'view_header.php';

require_once INCLUDE_ROOT.'admin/admin_nav.php';
?>
<h2>Delete study <small>has <?=($resultCount['begun']+$resultCount['finished'])?> result rows</small></h2>
<?php
if(isset($msg)) echo '<div class="alert '.$alertclass.' span6">'.$msg.'</div>';
?>
<div class="span7">
<form method="post" action="<?=WEBROOT?>admin/<?=$study->name?>/delete_study">
	
	<label>Type the study's name to confirm its deletion<br>
		<input name="delete_confirm" title="Confirm" type="text" placeholder="Study-Name"></label>
	
	<input name="delete" class="btn btn-danger hastooltip" title="Delete the entire study permanently" type="submit" value="Delete the entire study permanently (<?=($resultCount['begun']+$resultCount['finished'])?> result rows)">
	
</form>

</div>

<?php
require_once INCLUDE_ROOT.'view_footer.php';
