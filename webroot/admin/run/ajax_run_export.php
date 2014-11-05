<?php

/* @var $site Site */
/* @var $run Run */

if(is_ajax_request() && ($units = $site->request->arr('units')) && ($name = $site->request->str('name')) && preg_match('/^[a-z0-9_\s]+$/i', $name)) {
	if (!($saved = $run->exportUnits($units, $name))) {
        bad_request_header();
		echo $site->renderAlerts();
	} else {
		echo 'OK';
	}
} else {
	bad_request_header();
}