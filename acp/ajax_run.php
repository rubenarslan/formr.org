<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "admin/admin_header.php";
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/Run.php";


if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):
	if($run->valid)
	{
		alert('<strong>Success.</strong> Run "'.$run->name . '" was created.','alert-success');
	}
	else
		alert('<strong>Sorry.</strong> '.implode($run->errors),'alert-error');

endif;

echo $site->renderAlerts();