<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/StudyX.php";
if(!$user->admin) {
	alert("<strong>Error:</strong> Only admins have access.");
	redirect_to("index.php");
}
if(isset($_GET['study_name'])):
	$study = new StudyX($_GET['study_name']);

	if(!$study->valid)
	{
		alert("<strong>Error:</strong> Study broken.");
		redirect_to("index.php");
	}
	elseif(!$user->createdStudy($study))
	{
		alert("<strong>Error:</strong> Not your study.");
		redirect_to("index.php");
	}
endif;