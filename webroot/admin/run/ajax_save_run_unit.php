<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/RunUnit.php";

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):

	require_once INCLUDE_ROOT."Model/RunUnit.php";
	$unit_factory = new RunUnitFactory();
	if(isset($_POST['run_unit_id'])):
		$unit_info = $run->getUnitAdmin($_POST['run_unit_id']);

		$unit = $unit_factory->make($fdb,null,$unit_info);
		
		$unit->create($_POST);
		if($unit->valid):
				if(isset($_POST['unit_id'])):
					alert('<strong>Success.</strong> '.ucfirst($unit->type).' unit was updated.','alert-success');
				endif;
				echo $unit->displayForRun($site->renderAlerts());
		else:
			alert('<strong>Sorry.</strong> '.implode($unit->errors),'alert-danger');
		endif;
	endif;
endif;

echo $site->renderAlerts();
