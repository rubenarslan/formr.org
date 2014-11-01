<?php

/* @var $site Site */
/* @var $run Run */

if(is_ajax_request() && ($units = $site->request->arr('units'))) {
	if (!($saved = $run->exportUnits($units))) {
        bad_request_header();
		alert('<strong>Error.</strong> '.implode($run->errors,"<br>"), 'alert-danger');
		echo $site->renderAlerts();
	} else {
		echo 'OK';
	}
} else {
	bad_request_header();
}