<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "admin/admin_header.php";
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/RunUnit.php";

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):
	if(isset($_GET['unit_id'])):
		$unit = $run->getUnitAdmin($_GET['unit_id']);
		$unit = makeUnit($fdb,null,$unit);
		
		echo $unit->displayForRun();
	endif;
endif;

