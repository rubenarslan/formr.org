<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "admin/admin_header.php";
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/RunUnit.php";
require_once INCLUDE_ROOT . "Model/Run.php";
$run = new Run($fdb, $_GET['run_name']);
require_once INCLUDE_ROOT . "Model/StudyX.php";


if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):
	if(!empty($_POST) AND isset($_POST['study_name'])):
		$unit = new StudyX($_POST['study_name']);
		if($unit->id AND $unit->addToRun($run->id, $_POST['position'])  ):
			alert('<strong>Success.</strong> Study '.h($_POST['study_name']).' was added to run \''.h($run->name).'\'.','alert-success');
			echo $unit->displayForRun($_POST['position'],$site->renderAlerts());
		else:
			alert('<strong>Sorry.</strong> '.implode($unit->errors),'alert-error');
		endif;
	else:
		$mock_unit = new StudyX(null);
		echo $mock_unit->displayForRun(@$_GET['position']);
	endif;
endif;

