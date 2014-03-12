<?php
require_once INCLUDE_ROOT . "Model/Site.php";

if(!$user->isAdmin()) {
	alert("<strong>Sorry:</strong> Only admins have access.",'alert-info');
	redirect_to("index");
}
if(strpos($_SERVER['SCRIPT_NAME'],'/superadmin/')!==FALSE AND !$user->isSuperAdmin()) {
	alert("<strong>Sorry:</strong> Only superadmins have access.",'alert-info');
	redirect_to("index");
}

if(strpos($_SERVER['SCRIPT_NAME'],'/survey/')!==FALSE AND strpos($_SERVER['SCRIPT_NAME'],'/survey/add_survey.php')===FALSE):
	if(isset($_GET['study_name'])):
		require_once INCLUDE_ROOT . "Model/Study.php";

		$study = new Study($fdb,null,array('name' => $_GET['study_name']));

		if(!$study->valid):
			alert("<strong>Error:</strong> Survey does not exist.",'alert-danger');
			not_found();
		elseif(!$user->created($study)):
			alert("<strong>Error:</strong> Not your survey.",'alert-danger');
			redirect_to("/index");
		endif;
	else:
		alert("<strong>Error:</strong> No survey specified.",'alert-danger');
		not_found();
	endif;
elseif(strpos($_SERVER['SCRIPT_NAME'],'/run/')!==FALSE AND strpos($_SERVER['SCRIPT_NAME'],'/run/add_run.php')===FALSE):
	if(isset($_GET['run_name'])):
		require_once INCLUDE_ROOT . "Model/Run.php";
		$run = new Run($fdb, $_GET['run_name']);
	
		if(!$run->valid):
			alert("<strong>Error:</strong> Run does not exist.",'alert-danger');
			not_found();
		elseif(!$user->created($run)):
			alert("<strong>Error:</strong> Not your run.",'alert-danger');
			redirect_to("/index");
		endif;
	else:
		alert("<strong>Error:</strong> No run specified.",'alert-danger');
		not_found();
	endif;
endif;

$css = (isset($css)?$css:'') . '<link rel="stylesheet" href="'.WEBROOT.'assets/admin.css" type="text/css" media="screen">';
