<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/RunUnit.php";

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):
	$type = $_GET['type'];
	if(!in_array($type, array('Survey','Pause','Email','External','Page','SkipBackward','SkipForward','Shuffle'))):
		die ("INVALID MODULE");
	endif;

	require_once INCLUDE_ROOT . "Model/$type.php";
	require_once INCLUDE_ROOT."Model/RunUnit.php";
	$unit_factory = new RunUnitFactory();
	
	if(isset($_POST['unit_id'])) $unit_id = $_POST['unit_id'];
	else $unit_id = null;
	if(is_array($_POST['position'])) $position = current($_POST['position']);
	else $position = $_POST['position'];
	
	$unit = $unit_factory->make($fdb,null,array(
		'type' => $type,
		'unit_id'=> $unit_id, 
		'run_id' => $run->id,
		'position' => $position
	));
	$unit->create($_POST);
	if($unit->valid):
			if(isset($_POST['unit_id'])):
				alert('<strong>Success.</strong> '.$type.' unit was updated.','alert-success');
			else:
				$unit->addToRun($run->id, $_POST['position']);
				alert('<strong>Success.</strong> '.$type.' unit was created.','alert-success');
			endif;
			echo $unit->displayForRun($site->renderAlerts());
	else:
		alert('<strong>Sorry.</strong> '.implode($unit->errors),'alert-danger');
	endif;
endif;

echo $site->renderAlerts();
