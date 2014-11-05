<?php

/* @var $site Site */
/* @var $run Run */

if(is_ajax_request()) {
	// If only showing dialog then show it and exit
	$dialog_only = $site->request->bool('dialog');
	if ($dialog_only) {
		// Read on exported runs from configured directory
		$dir = Config::get('run_exports_dir');
		if (!($exports = (array) get_run_dir_contents($dir))) {
			$exports = array();
		}

		Template::load('run_import_dialog', array('exports' => $exports));
		exit;
	}

	// Else do actual import of specified units
	$json_string = $site->request->str('string');
	$start_position = $site->request->int('position', 1);

	if (!$json_string) {
		bad_request_header();
		exit(1);
	}

	if (!($imports = $run->importUnits($json_string, $start_position))) {
        bad_request_header();
		echo $site->renderAlerts();
	} else {
		json_header();
		echo json_encode($imports);
		exit(0);
	}
} else {
	bad_request_header();
}