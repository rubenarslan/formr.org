<?php
/* @var $run Run */

if(is_ajax_request()) :
	if(isset($_GET['run_unit_id'])):
		if(isset($_GET['special']))
			$special = $_GET['special'];
		else $special = false;
		
		$unit_info = $run->getUnitAdmin($_GET['run_unit_id'], $special);
		$unit_factory = new RunUnitFactory();
		$unit = $unit_factory->make($fdb, null, $unit_info);
		
		echo $unit->displayForRun();
		exit;
	endif;
endif;

bad_request_header();
$alert_msg = "<strong>Sorry, missing unit.</strong> ";
if(isset($unit)) $alert_msg .= implode($unit->errors);
alert($alert_msg, 'alert-danger');
echo $site->renderAlerts();