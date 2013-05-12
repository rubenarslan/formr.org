<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "admin/admin_header.php";
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/RunUnit.php";
require_once INCLUDE_ROOT . "Model/Run.php";
$run = new Run($fdb, $_GET['run_name']);

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):
	if(isset($_GET['unit_id'])):
		$unit = $run->getUnitAdmin($_GET['unit_id']);
		$type = $unit['type'];
		if(!in_array($type, array('Survey','Break','Email','External','Page','Branch','End'))) die('imp type');

		if($type==='Survey'):
			$study_data = $fdb->prepare("SELECT name FROM `survey_studies` WHERE id = :study_id LIMIT 1");
			$study_data->bindParam(":study_id",$unit['unit_id']);
			$study_data->execute() or die(print_r($study_data->errorInfo(), true));
			$vars = $study_data->fetch(PDO::FETCH_ASSOC);
			require_once INCLUDE_ROOT . "Model/StudyX.php";
			$unit = new StudyX($vars['name']);

		else:
			require_once INCLUDE_ROOT . "Model/$type.php";
			$unit = new $type($session,$unit);
		endif;
		
		echo $unit->displayForRun();
	endif;
endif;

