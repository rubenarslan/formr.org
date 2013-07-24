<?php
require_once '../../../define_root.php';require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/RunUnit.php";

$to_delete = $_POST['unit_id'];
$run_unit = new RunUnit($fdb);
$run_unit->id = $to_delete;

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):
	if($run_unit->removeFromRun($run->id))
	{
		alert('<strong>Success.</strong> Unit with ID '.h($to_delete).' was deleted.','alert-success');
	}
	else
		alert('<strong>Sorry.</strong> '.implode($run_unit->errors),'alert-error');

endif;

echo $site->renderAlerts();