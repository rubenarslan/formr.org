<?
require_once 'define_root.php';

require_once INCLUDE_ROOT . "config/config.php";
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/Session.php";
require_once INCLUDE_ROOT . 'Model/StudyX.php'; # Study , nothing is echoed yet

$study = new StudyX($_GET['study_name']);

$has_access = false;
if($study->public):
	$has_access = true;
elseif(isset($currentUser) AND $currentUser->ownsStudy($study->id)):
	$has_access = true;
elseif($study->registration_required AND userIsLoggedIn()):
	$has_access = true;
elseif($study->settings['closed_user_pool']):
	$has_access = false;
endif;


if($has_access):
	$session = new Session(null,$study);
	$session->create();
	
	$_SESSION['session'] = $session->session;
	
	$goto = "{$study->name}/survey/";
	if(isset($run))
		$goto .= "&run_id=".$run->id;
	redirect_to($goto);
else:
	redirect_to("index.php?msg=noaccess");	
endif;