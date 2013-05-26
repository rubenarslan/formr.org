<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "admin/admin_header.php";
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/RunUnit.php";

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):
	if(isset($_GET['unit_id'])):
		$unit = $run->getUnitAdmin($_GET['unit_id']);
		$type = $unit['type'];
		if(!in_array($type, array('Survey','Pause','Email','External','Page','Branch','End'))) die('imp type');

		if($type==='Survey'):
			$type = 'Study';
		endif;

		require_once INCLUDE_ROOT . "Model/$type.php";
		$unit = new $type($fdb,null,$unit);
		
		echo $unit->displayForRun();
	endif;
endif;

