<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "admin/admin_header.php";
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/Run.php";
$run = new Run($fdb, $_GET['run_name']);
require_once INCLUDE_ROOT . "Model/RunUnit.php";

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):
	$type = $_GET['type'];
	if(!in_array($type, array('Survey','Pause','Email','External','Page','Branch','End'))) die('imp type');
	if($type == 'Survey') $type = 'StudyX';

	require_once INCLUDE_ROOT . "Model/$type.php";
	
	if($type!='StudyX'):
		if(isset($_POST['unit_id'])):
			$unit = new $type($fdb, null, array('unit_id'=>$_POST['unit_id']));
			$unit->create($_POST);
			if($unit->valid):
				alert('<strong>Success.</strong> '.$type.' unit was updated.','alert-success');
				echo $unit->displayForRun($site->renderAlerts());
			else:
				alert('<strong>Sorry.</strong> '.implode($unit->errors),'alert-error');
			endif;
		else:
			$unit = new $type($fdb,null,null);
			$unit->create($_POST);
			if($unit->valid):
				$unit->addToRun($run->id, $_POST['position']);
				alert('<strong>Success.</strong> '.$type.' unit was created.','alert-success');
				echo $unit->displayForRun($site->renderAlerts());
			else:
				alert('<strong>Sorry.</strong> '.implode($unit->errors),'alert-error');
			endif;
		endif;
	else:
		if(isset($_POST['unit_id'])):
			$unit = new $type($fdb, null, array('unit_id'=>$_POST['unit_id']));
			if($unit->valid):
				$unit->addToRun($run->id, current($_POST['position']));
				alert('<strong>Success.</strong> '.$type.' unit was added.','alert-success');
				echo $unit->displayForRun($site->renderAlerts());
			else:
				alert('<strong>Sorry.</strong> '.implode($unit->errors),'alert-error');
			endif;
		else:
			$unit = new StudyX($fdb,null,null);
			$unit->position = $_POST['position'];
			echo $unit->displayForRun($site->renderAlerts());
		endif;
	endif;
	
endif;

echo $site->renderAlerts();
