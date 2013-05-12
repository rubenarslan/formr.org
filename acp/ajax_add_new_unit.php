<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "admin/admin_header.php";
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/RunUnit.php";

$type = $_POST['type'];
if(!in_array($type, array('Break','Email','External','Page','Branch','End'))) die('imp type');

require_once INCLUDE_ROOT . "Model/$type.php";
$unit = new $type(null,null);

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):
	if($unit->create() AND $unit->addToRun($run->id, $_POST['position']))
	{
		alert('<strong>Success.</strong> Unit with ID '.h($to_delete).' was deleted.','alert-success');
	}
	else
		alert('<strong>Sorry.</strong> '.implode($run_unit->errors),'alert-error');

endif;

echo $site->renderAlerts();
