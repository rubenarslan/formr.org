<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/RunUnit.php";

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):

	if(isset($_GET['run_unit_id'])):
		$unit_info = $run->getUnitAdmin($_GET['run_unit_id']);

		require_once INCLUDE_ROOT."Model/RunUnit.php";
		$unit_factory = new RunUnitFactory();
		$unit = $unit_factory->make($fdb,null,$unit_info);
		
		echo $unit->displayForRun();
	else:
		echo "Missing unit";
		exit;
	endif;
endif;

