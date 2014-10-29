<?php

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
