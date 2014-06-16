<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "Model/Site.php";


if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):
	$saved = $run->saveSettings($_POST);
	if($saved):
		echo '';
		exit;
	else:
		bad_request_header();
		alert('<strong>Error.</strong> '.implode($run->errors,"<br>"),'alert-danger');
		echo $site->renderAlerts();
	endif;
endif;
