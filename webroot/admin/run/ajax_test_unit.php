<?php

$run = new Run($fdb, $site->request->str('run_name'));

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):
	if(isset($_GET['run_unit_id'])):
		if(isset($_GET['special']))
			$special = $_GET['special'];
		else $special = false;
		
		$unit = $run->getUnitAdmin($_GET['run_unit_id'], $special);
		$unit_factory = new RunUnitFactory();
		$unit = $unit_factory->make($fdb,null,$unit);
		
		$unit->test();
		echo $site->renderAlerts();
		exit;
	endif;
endif;

bad_request_header();
$alert_msg = "'<strong>Sorry.</strong> '";
if(isset($unit)) $alert_msg .= implode($unit->errors);
alert($alert_msg,'alert-danger');

echo $site->renderAlerts();