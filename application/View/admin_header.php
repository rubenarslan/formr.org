<?php
if(!$user->isAdmin()) {
	alert("<strong>Sorry:</strong> Only admins have access.",'alert-info');
	access_denied();
}
if($site->inSuperAdminArea() AND !$user->isSuperAdmin()) {
	alert("<strong>Sorry:</strong> Only superadmins have access.",'alert-info');
	access_denied();
}

if($site->inAdminSurveyArea() AND strpos($site->getPath(), '/survey/add_survey') === FALSE):
    if($site->request->str('study_name')):
		$study = new Survey($fdb, null, array('name' => $site->request->str('study_name')));
		if(!$study->valid):
			alert("<strong>Error:</strong> Survey does not exist.",'alert-danger');
			not_found();
		elseif(!$user->created($study)):
			alert("<strong>Error:</strong> Not your survey.",'alert-danger');
			access_denied();
		endif;
	else:
		redirect_to(WEBROOT . 'admin/survey/add_survey');
	endif;
elseif($site->inAdminRunArea() AND strpos($site->getPath(), '/run/add_run') === FALSE):
	if($site->request->str('run_name')):
		$run = new Run($fdb, $site->request->str('run_name'));
	
		if(!$run->valid):
			alert("<strong>Error:</strong> Run does not exist.",'alert-danger');
			not_found();
		elseif(!$user->created($run)):
			alert("<strong>Error:</strong> Not your run.",'alert-danger');
			access_denied();
		endif;
	else:
		redirect_to(WEBROOT . 'admin/run/add_run');
	endif;
endif;