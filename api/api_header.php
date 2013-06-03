<?php
require_once INCLUDE_ROOT . "Model/Site.php";
if(isset($_GET['run_name'])):
	require_once INCLUDE_ROOT . "Model/Run.php";
	require_once INCLUDE_ROOT . "Model/RunSession.php";
	
	$run = new Run($fdb, $_GET['run_name']);
	
	if(!$run->valid):
		alert("<strong>Error:</strong> Run broken.",'alert-error');
	elseif(!isset($_POST['api_secret']) OR !$run->hasApiAccess($_POST['api_secret'])):
		alert("<strong>Error.</strong> Wrong api secret.",'alert-error');
	else:

	endif;
else:
	alert("<strong>Error.</strong> This run does not exist.",'alert-error');
endif;

$problems = $site->renderAlerts();
if(!empty($problems)):
	echo $problems;
	exit;
endif;