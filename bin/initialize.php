#!/usr/bin/php
<?php
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();
$settings = $db->count('survey_settings');

if($settings > 0) {
	print("Settings were already initialized");
} else {
	$default_settings = Config::get("content_settings");

	foreach ($default_settings as $setting => $value) {

		if(is_bool($value)) {
			$value = $value ? "true" : false;
		}
		$data = array(
			"setting" => $setting,
			"value" => $value
		);
		
		$inserted = $db->insert('survey_settings', $data);
	}
	
	print("Content settings have now been initialized");
}
