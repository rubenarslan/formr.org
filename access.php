<?php
//http://localhost:8888/zwang/survey/access.php?run_name=run9&code=04838c56a90ca4e4e3cae8a4a1fabded948f5beb3c07f2cac0be4bca91966e45
require_once 'define_root.php';
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/UnitSession.php";

if(isset($_GET['run_name']) AND isset($_GET['code']) AND strlen($_GET['code'])==64):
	$test_code = $_GET['code'];
	$user->user_code = $test_code;
	
	$_SESSION['session'] = $test_code;
	
	redirect_to($_GET['run_name']);
else:
	alert("<strong>Sorry.</strong> Something went wrong when you tried to access.",'alert-error');
	redirect_to("index");
endif;