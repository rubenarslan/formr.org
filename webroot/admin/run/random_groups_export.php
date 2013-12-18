<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT.'View/admin_header.php';

$g_users = $fdb->prepare("SELECT 
	`survey_run_sessions`.session,
	`survey_unit_sessions`.id AS session_id,
	`survey_runs`.name AS run_name,
	`survey_run_units`.position,
	`survey_units`.type AS unit_type,
	`survey_unit_sessions`.created,
	`survey_unit_sessions`.ended,
	`survey_users`.email,
	`shuffle`.group
	
	
FROM `survey_unit_sessions`

LEFT JOIN `shuffle`
ON `shuffle`.session_id = `survey_unit_sessions`.id
LEFT JOIN `survey_run_sessions`
ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
LEFT JOIN `survey_users`
ON `survey_users`.id = `survey_run_sessions`.user_id
LEFT JOIN `survey_units`
ON `survey_unit_sessions`.unit_id = `survey_units`.id
LEFT JOIN `survey_run_units`
ON `survey_unit_sessions`.unit_id = `survey_run_units`.unit_id
LEFT JOIN `survey_runs`
ON `survey_runs`.id = `survey_run_units`.run_id
WHERE `survey_runs`.name = :run_name AND
`survey_units`.type = 'Shuffle'
ORDER BY `survey_run_sessions`.id DESC,`survey_unit_sessions`.id ASC;");
$g_users->bindParam(':run_name',$run->name);
$g_users->execute();

$users = array();
while($userx = $g_users->fetch(PDO::FETCH_ASSOC))
{
	unset($userx['session']);
	unset($userx['run_name']);
	unset($userx['unit_type']);
	unset($userx['ended']);
	unset($userx['position']);
#	$user['body'] = "<small title=\"{$user['body']}\">". substr($user['body'],0,50). "…</small>";
	
	$users[] = $userx;
}
require_once INCLUDE_ROOT.'Model/SpreadsheetReader.php';

$SPR = new SpreadsheetReader();
$SPR->exportCSV($users,"Shuffle_Run_".$run->name);
