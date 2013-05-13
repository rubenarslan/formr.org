<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "admin/admin_header.php";
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/RunUnit.php";
require_once INCLUDE_ROOT . "Model/Run.php";
$run = new Run($fdb, $_GET['run_name']);

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):
	if(isset($_POST['position'])):
		pr($_POST);
		$unit = $run->reorder($_POST['position']);
	endif;
endif;
