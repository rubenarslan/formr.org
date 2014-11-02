<?php

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):

	$unit_factory = new RunUnitFactory();
	$unit = $unit_factory->make($fdb, null, array('type' => $_GET['type'], 'position' => $_POST['position']));
	$unit->create($_POST);

	if($unit->valid):
		$unit->addToRun($run->id, $_POST['position']);
		alert('<strong>Success.</strong> '.ucfirst($unit->type).' unit was created.','alert-success');
		echo $unit->displayForRun($site->renderAlerts());
		exit;
	endif;
endif;

bad_request_header();
$alert_msg = "'<strong>Sorry.</strong> '";
if(isset($unit)) $alert_msg .= implode($unit->errors);
alert($alert_msg,'alert-danger');

echo $site->renderAlerts();
