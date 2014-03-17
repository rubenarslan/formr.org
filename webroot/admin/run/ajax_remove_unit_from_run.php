<?php
require_once '../../../define_root.php';require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/RunUnit.php";

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):

	if(isset($_POST['run_unit_id'])):
		$unit_info = $run->getUnitAdmin($_POST['run_unit_id']);

		require_once INCLUDE_ROOT."Model/RunUnit.php";
		$unit_factory = new RunUnitFactory();
		$unit = $unit_factory->make($fdb,null,$unit_info);
		
		if($unit->removeFromRun()):
			alert('<strong>Success.</strong> Unit with ID '.h($_POST['run_unit_id']).' was deleted.','alert-success');
		else:
			alert('<strong>Sorry, could not remove unit.</strong> '.implode($unit->errors),'alert-danger');
		endif;
	endif;
endif;

echo $site->renderAlerts();