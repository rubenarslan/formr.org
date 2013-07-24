<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "Model/Email.php";
require_once INCLUDE_ROOT . 'Model/RunSession.php';
$run_session = new RunSession($fdb, $run->id, null, $_GET['session']);

$new_position = $_POST['new_position'];
$_POST = array();
if(!$run_session->forceTo($new_position)):
	alert('<strong>Something went wrong with the position change.</strong> in run '.$run->name, 'alert-error');
endif;

redirect_to("admin/run/".$run->name."/user_overview");

