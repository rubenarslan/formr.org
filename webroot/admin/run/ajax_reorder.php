<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/RunUnit.php";

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):
	if(isset($_POST['position'])):
		$unit = $run->reorder($_POST['position']);
		exit;
	endif;
endif;

bad_request_header();
$alert_msg = "'<strong>Sorry.</strong> '";
if(isset($unit)) $alert_msg .= implode($unit->errors);
alert($alert_msg,'alert-danger');

echo $site->renderAlerts();
