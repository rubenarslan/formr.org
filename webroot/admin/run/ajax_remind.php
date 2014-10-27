<?php
// todo: because sending an out-of-order reminder email means all kinds of problems for logging etc, I probably need to rethink run_session ordering - atm by most recent unit, but this does not allow for a quick out-of-order email like this one. should probably be using a current_unit field in the run_session (ordering by highest position is not possible because of loops)

// find the last email unit
$email = $run->getReminder($_GET['session'],$_GET['run_session_id']);
if($email->exec()!==false):
	alert('<strong>Something went wrong with the reminder.</strong> in run '.$_GET['run_name'], 'alert-danger');
	bad_request_header();
endif;

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):
	echo $site->renderAlerts();
	exit;
else:
	redirect_to("admin/run/".$run->name."/user_overview");
endif;