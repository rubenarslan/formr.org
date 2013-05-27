<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "api/api_header.php";

$run_session = new RunSession($fdb, $run->id, null, null);
if(isset($_POST['code']):
	if(is_array($_POST['code'])):
		foreach($_POST['code'] AS $code):
			$run_session->create($code) or die('Error when adding sessions');
		endforeach;
	else:
		$run_session->create($_POST['code']) or die('Error when adding  when adding session');
	endif;
else
	$run_session->create();

echo 'Success!';