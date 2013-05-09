<?
require_once '../define_root.php';
require_once INCLUDE_ROOT.'admin/admin_header.php';
require_once INCLUDE_ROOT . "Model/Session.php";

$has_access = false;
if($user->createdStudy($study)):
	$has_access = true;
endif;


if($has_access):
	$session = new Session(null,$study);
	$session->create();
	
	$_SESSION['session'] = $session->session;
	
	$goto = "{$study->name}/survey/";
	if(isset($run))
		$goto .= "&run_id=".$run->id;
	
	alert("<strong>Go ahead.</strong> You can test the study now.",'alert-info');
	
	redirect_to($goto);
else:
	alert("<strong>Sorry.</strong> You don't have access to this study",'alert-error');
	redirect_to("index.php");	
endif;