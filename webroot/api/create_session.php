<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "api/api_header.php";
$i= 0;
$run_session = new RunSession($fdb, $run->id, null, null);
if(isset($_POST['code'])):
	if(is_array($_POST['code'])):
		foreach($_POST['code'] AS $code):
			$i += $run_session->create($code);
		endforeach;
	else:
		$i += $run_session->create($_POST['code']);
	endif;
else:
	$run_session->create() or die('Error when adding  when creating session');
	$i++;
endif;

echo 'Success. '. $i. ' users added.';