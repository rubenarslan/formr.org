<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT.'View/admin_header.php';
require_once INCLUDE_ROOT . "Model/UnitSession.php";


$has_access = false;
if($user->created($study)):
	$has_access = true;
endif;


if($has_access):
	$test_code = bin2hex(openssl_random_pseudo_bytes(32));
	$test_code = 'TEST_CODE'.substr($test_code,9);
	$session = new UnitSession($fdb, null, $study->id);
	$session->create();
	
	$_SESSION['session'] = $test_code;
	$_SESSION['survey_test_id'] = $session->id;
	$_SESSION['test_survey_name'] = $study->name;
	
	$goto = "fake_test_run";
	
	alert("<strong>Go ahead.</strong> You can test the study now.",'alert-info');
	
	redirect_to($goto);
else:
	alert("<strong>Sorry.</strong> You don't have access to this study",'alert-error');
	redirect_to("index");	
endif;