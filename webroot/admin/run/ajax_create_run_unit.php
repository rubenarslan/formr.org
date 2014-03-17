<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/RunUnit.php";

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):

	require_once INCLUDE_ROOT."Model/RunUnit.php";
	$unit_factory = new RunUnitFactory();

	$unit = $unit_factory->make($fdb,null,array('type' => $_GET['type'], 'position' => $_POST['position']));
	$unit->create($_POST);

	if($unit->valid):
		$unit->addToRun($run->id, $_POST['position']);
		alert('<strong>Success.</strong> '.ucfirst($unit->type).' unit was created.','alert-success');
		echo $unit->displayForRun($site->renderAlerts());
	endif;
endif;

echo $site->renderAlerts();
