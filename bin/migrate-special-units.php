#!/usr/bin/php
<?php

/**
 * Migrate existing special units to new table
 * 
 */

require_once dirname(__FILE__) . '/../define_root.php';


function migrateSpecialUnits() {
	$sql = "SELECT survey_runs.id, survey_runs.reminder_email, survey_runs.service_message, survey_runs.overview_script FROM survey_runs";
	$db = DB::getInstance();
	$stmt = $db->prepare($sql);
	$stmt->execute();
	while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
		if (!empty($row['reminder_email'])) {
			$data = array(
				'id' => $row['reminder_email'],
				'run_id' => $row['id'],
				'type' => 'ReminderEmail',
				'description' => $db->findValue('survey_run_units', array('unit_id' => $row['reminder_email']), 'description'),
			);
			$db->insert_update('survey_run_special_units', $data);
		}
		
		if (!empty($row['service_message'])) {
			$data = array(
				'id' => $row['service_message'],
				'run_id' => $row['id'],
				'type' => 'ServiceMessagePage',
				'description' => $db->findValue('survey_run_units', array('unit_id' => $row['service_message']), 'description'),
			);
			$db->insert_update('survey_run_special_units', $data);
		}
		
		if (!empty($row['overview_script'])) {
			$data = array(
				'id' => $row['overview_script'],
				'run_id' => $row['id'],
				'type' => 'OverviewScriptPage',
				'description' => $db->findValue('survey_run_units', array('unit_id' => $row['overview_script']), 'description'),
			);
			$db->insert_update('survey_run_special_units', $data);
		}
	}
}

migrateSpecialUnits();
echo "\n DONE \n";