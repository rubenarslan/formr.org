<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "admin/admin_header.php";
require_once INCLUDE_ROOT . "Model/Email.php";
require_once INCLUDE_ROOT . "Model/Run.php";
$run = new Run($fdb, $_GET['run_name']);

require_once INCLUDE_ROOT . 'Model/RunSession.php';
$run_session = new RunSession($fdb, $run->id, null, $_GET['session']);

if(!$run_session->forceTo($_POST['new_position']))
	alert('<strong>Something went wrong with the position change.</strong> in run '.$_GET['run_name'], 'alert-error');

redirect_to("acp/user_overview");
