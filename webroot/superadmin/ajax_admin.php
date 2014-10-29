<?php
if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):

	if(isset($_POST['admin_level']) AND isset($_GET['user_id'])):
		$user_to_edit = new User($fdb, $_GET['user_id'], null);
		if(!$user_to_edit->setAdminLevelTo($_POST['admin_level'])):
			alert('<strong>Something went wrong with the admin level change.</strong>', 'alert-danger');
			bad_request_header();
		endif;
	endif;
	echo $site->renderAlerts();
	exit;
else:
	redirect_to("/");
endif;