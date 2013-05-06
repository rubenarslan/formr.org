<?php
require_once "../includes/define_root.php";
require_once INCLUDE_ROOT.'admin/admin_header.php';

if(isset($_POST['delete']) AND trim($_POST['delete_confirm']) === $study->name)
{
	$study->deleteResults();
	redirect_to(WEBROOT."admin/{$study->name}/delete_results?msg=Successfully+deleted+all+results");
}
elseif(isset($_POST['delete']))
{
	$msg = "<b>Error:</b> Study's name must match '{$study->name}' to delete results.";
	$alertclass = 'alert-error';
}
if(isset($_GET['msg']))
{
	$msg = "<b>Success:</b> ".h($_GET['msg']);
	$alertclass = 'alert-success';
}

$resultCount = $study->getResultCount();


require_once INCLUDE_ROOT.'view_header.php';

require_once INCLUDE_ROOT.'admin/admin_nav.php';
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
Please <a href="'.WEBROOT.'admin/'.$study->name.'/show_results">review the existing results</a> before deleting them.</div>';
?>
<div class="span7">
<form method="post" action="<?=WEBROOT?>admin/<?=$study->name?>/delete_results">
	
	<label>Type the study's name to confirm <br>
		<input name="delete_confirm" title="Confirm" type="text" placeholder="Study-Name"></label>
	
	<input name="delete" class="btn btn-danger hastooltip" title="Delete all results permanently" type="submit" value="Delete <?= ($resultCount['begun']+$resultCount['finished'])?> results">
	
</form>

</div>

<?php
require_once INCLUDE_ROOT.'view_footer.php';
