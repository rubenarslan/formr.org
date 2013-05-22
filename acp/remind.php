<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "admin/admin_header.php";
require_once INCLUDE_ROOT . "Model/Email.php";


$g_email = $fdb->prepare("SELECT 
	`survey_units`.id,
	MAX(`survey_run_units`.position) AS position
	
FROM `survey_runs`
LEFT JOIN `survey_run_units`
ON `survey_runs`.id = `survey_run_units`.run_id
LEFT JOIN `survey_units`
ON `survey_units`.id = `survey_run_units`.unit_id

WHERE `survey_units`.type = 'Email'
AND `survey_runs`.name = :name;");
$g_email->bindParam(':name',$_GET['run_name']);
$g_email->execute();
$run = $g_email->fetch(PDO::FETCH_ASSOC);

if($run AND trim($_GET['email'])!=''):

	$email = new Email($fdb, $_GET['session'], 
		array(
		'run_name' => $_GET['run_name'],
		'unit_id' => $run['id']
		)
	);
	if($email->remind($_GET['email'])===true):
		alert('<strong>Reminder sent.</strong> in run '.$_GET['run_name'], 'alert-info');
		redirect_to("acp/user_overview");
	endif;
endif;

alert('<strong>Something went wrong with the reminder.</strong> in run '.$_GET['run_name'], 'alert-error');
redirect_to("acp/user_overview");
