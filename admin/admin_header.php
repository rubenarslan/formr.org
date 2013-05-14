<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/StudyX.php";
if(!$user->loggedIn() AND !$user->isAdmin()) {
	alert("<strong>Sorry:</strong> Only admins have access.",'alert-info');
	redirect_to("index.php");
}
if(isset($_GET['study_name'])):
	$study = new StudyX($fdb,null,array('name' => $_GET['study_name']));

	if(!$study->valid)
	{
		alert("<strong>Error:</strong> Study broken.",'alert-error');
		redirect_to("index.php");
	}
	elseif(!$user->createdStudy($study))
	{
		alert("<strong>Error:</strong> Not your study.",'alert-error');
		redirect_to("index.php");
	}
endif;