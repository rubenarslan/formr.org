<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "survey/admin_header.php";
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/RunUnit.php";

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):
	if(isset($_POST['on'])):
		if(!$run->toggleCron((bool)$_POST['on']))
			echo 'Error!';
	endif;
endif;
