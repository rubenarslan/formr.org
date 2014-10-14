<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/RunUnit.php";

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):

	require_once INCLUDE_ROOT."Model/RunUnit.php";
	$unit_factory = new RunUnitFactory();
	if(isset($_POST['run_unit_id'])):
		if(isset($_POST['special']))
			$special = $_POST['special'];
		else $special = false;
		
		$unit_info = $run->getUnitAdmin($_POST['run_unit_id'], $special);
		
		$unit = $unit_factory->make($fdb,null,$unit_info);
		
		$unit->create($_POST);
		if($unit->valid):
				if(isset($_POST['unit_id'])):
					alert('<strong>Success.</strong> '.ucfirst($unit->type).' unit was updated.','alert-success');
				endif;
				echo $unit->displayForRun($site->renderAlerts());
				exit;
		endif;
	endif;
endif;
bad_request_header();
$alert_msg = "<strong>Sorry.</strong> Something went wrong while saving. Please contact formr devs, if this problem persists.";
if(isset($unit)) $alert_msg .= implode($unit->errors);
alert($alert_msg,'alert-danger');

echo $site->renderAlerts();
