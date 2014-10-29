<?php
if(isset($_GET['run_name'])):
	$run = new Run($fdb, $_GET['run_name']);
	if(!$run->valid):
		alert("<strong>Error:</strong> Run broken.",'alert-danger');
	elseif(!isset($_POST['api_secret']) OR !$run->hasApiAccess($_POST['api_secret'])):
		alert("<strong>Error.</strong> Wrong api secret.",'alert-danger');
	else:

	endif;
else:
	alert("<strong>Error.</strong> This run does not exist.", 'alert-danger');
endif;

$problems = $site->renderAlerts();
if(!empty($problems)):
	echo $problems;
	exit;
endif;