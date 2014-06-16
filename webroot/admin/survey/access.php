<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT.'View/admin_header.php';
require_once INCLUDE_ROOT . "Model/UnitSession.php";


$has_access = false;
if($user->created($study)):
	$has_access = true;
endif;


if($has_access):
	$session = new UnitSession($fdb, null, $study->id);
	$session->create();
	
	$_SESSION['dummy_survey_session'] = array(
		"session_id" => $session->id,
		"unit_id" => $study->id,
		"run_session_id" => $session->run_session_id,
		"run_name" => "fake_test_run",
		"survey_name" => $study->name
	);
	
	alert("<strong>Go ahead.</strong> You can test the study ".$study->name." now.",'alert-info');
	redirect_to("fake_test_run");
else:
	alert("<strong>Sorry.</strong> You don't have access to this study",'alert-danger');
	redirect_to("index");	
endif;