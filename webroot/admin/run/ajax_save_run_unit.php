<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/RunUnit.php";

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):
	$type = $_GET['type'];
	if(!in_array($type, array('Survey', 'Study','Pause','Email','External','Page','SkipBackward','SkipForward','End'))) die;
	if($type == 'Survey') $type = 'Study';

	require_once INCLUDE_ROOT . "Model/$type.php";
	
	if($type!='Study'):
		if(isset($_POST['unit_id'])):
			$unit = makeUnit($fdb,null,array('type' => $type,'unit_id'=>$_POST['unit_id']));
			$unit->create($_POST);
			if($unit->valid):
				alert('<strong>Success.</strong> '.$type.' unit was updated.','alert-success');
				echo $unit->displayForRun($site->renderAlerts());
			else:
				alert('<strong>Sorry.</strong> '.implode($unit->errors),'alert-danger');
			endif;
		else:
			$unit = makeUnit($fdb,null,array('type' => $type));
			$unit->create($_POST);
			if($unit->valid):
				$unit->addToRun($run->id, $_POST['position']);
				alert('<strong>Success.</strong> '.$type.' unit was created.','alert-success');
				echo $unit->displayForRun($site->renderAlerts());
			else:
				alert('<strong>Sorry.</strong> '.implode($unit->errors),'alert-danger');
			endif;
		endif;
	else:
		if(isset($_POST['unit_id'])):
			$unit = makeUnit($fdb, null, array('type' => $type,'unit_id'=>$_POST['unit_id']));
			if($unit->valid):
				$unit->addToRun($run->id, $_POST['position']);
				alert('<strong>Success.</strong> '.$type.' unit was added.','alert-success');
				echo $unit->displayForRun($site->renderAlerts());
			else:
				alert('<strong>Sorry.</strong> '.implode($unit->errors),'alert-danger');
			endif;
		else:
			$unit = new Study($fdb,null,array('type' => $type));
			if($_POST['position']==NULL OR $_POST['position']=='NaN') $_POST['position'] = 1;
			$unit->position = $_POST['position'];
			echo $unit->displayForRun($site->renderAlerts());
		endif;
	endif;
	
endif;

echo $site->renderAlerts();
