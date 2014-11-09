<?php

/* @var $site Site */
/* @var $run Run */

if(is_ajax_request() && ($units = $site->request->arr('units')) && ($name = $site->request->str('name')) && preg_match('/^[a-z0-9_\s]+$/i', $name)) {
	if (!($saved = $run->exportUnits($units, $name))) {
        bad_request_header();
		echo $site->renderAlerts();
	} else {
		echo basename($saved);
	}
} elseif (($file = $site->request->str('f')) && file_exists(Config::get('run_exports_dir') . '/' . $file)) {
	// sending file for download
	download_file(Config::get('run_exports_dir') . '/' . $file, true);
} else {
	bad_request_header();
}