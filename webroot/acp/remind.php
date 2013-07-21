<?php
// todo: because sending an out-of-order reminder email means all kinds of problems for logging etc, I probably need to rethink run_session ordering - atm by most recent unit, but this does not allow for a quick out-of-order email like this one. should probably be using a current_unit field in the run_session (ordering by highest position is not possible because of loops)
require_once '../define_root.php';
require_once INCLUDE_ROOT . "admin/admin_header.php";
require_once INCLUDE_ROOT . "Model/Email.php";

// find the last email unit
$g_email = $fdb->prepare("SELECT 
	`survey_units`.id,
	`survey_run_units`.position AS position
	
FROM `survey_runs`
LEFT JOIN `survey_run_units`
ON `survey_runs`.id = `survey_run_units`.run_id
LEFT JOIN `survey_units`
ON `survey_units`.id = `survey_run_units`.unit_id

WHERE `survey_units`.type = 'Email'
AND `survey_runs`.name = :name
ORDER BY `survey_run_units`.position DESC
LIMIT 1;");
$g_email->bindParam(':name',$_GET['run_name']);
$g_email->execute();
$Reminder = $g_email->fetch(PDO::FETCH_ASSOC);

if($Reminder AND trim($_GET['run_session_id'])!=''):

	$email = new Email($fdb, $_GET['session'], 
		array(
		'run_name' => $run->name,
		'unit_id' => $Reminder['id'],
		'session_id' => null,
		'run_session_id' => $_GET['run_session_id']
		)
	);
	if($email->remind($_GET['email'])===true):
		alert('<strong>Reminder sent.</strong> in run '.$_GET['run_name'], 'alert-info');
		redirect_to("acp/user_overview");
	endif;
endif;

alert('<strong>Something went wrong with the reminder.</strong> in run '.$_GET['run_name'], 'alert-error');
redirect_to("acp/user_overview");
