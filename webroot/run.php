<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "Model/Site.php";

$user = $site->loginUser($user);

if(isset($_GET['run_name'])):
	require_once INCLUDE_ROOT . "Model/Run.php";
	$run = new Run($fdb, $_GET['run_name']);

	$run->exec($user);
endif;