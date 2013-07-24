<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/RunUnit.php";
require_once INCLUDE_ROOT . "Model/Run.php";
$run = new Run($fdb, $_GET['run_name']);

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):
	if(isset($_GET['unit_id'])):
		$unit = $run->getUnitAdmin($_GET['unit_id']);
		$unit = makeUnit($fdb,null,$unit);
		
		$unit->test();
		echo $site->renderAlerts();
		
	endif;
endif;

