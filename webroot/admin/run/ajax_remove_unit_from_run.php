<?php
require_once '../../../define_root.php';require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/RunUnit.php";

if(isset($_POST['unit_id'])) $unit_id = $_POST['unit_id'];
else $unit_id = null;
if(is_array($_POST['position'])) $position = current($_POST['position']);
else $position = $_POST['position'];

$run_unit = new RunUnit($fdb, null, array(
	'unit_id'=> $unit_id, 
	'run_id' => $run->id,
	'position' => $position
));

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):
	if($run_unit->removeFromRun($run->id))
	{
		alert('<strong>Success.</strong> Unit with ID '.h($unit_id).' was deleted.','alert-success');
	}
	else
		alert('<strong>Sorry.</strong> '.implode($run_unit->errors),'alert-danger');

endif;

echo $site->renderAlerts();