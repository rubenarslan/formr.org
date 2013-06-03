<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "admin/admin_header.php";
require_once INCLUDE_ROOT . "Model/Site.php";

$del = $fdb->prepare('DELETE FROM `survey_unit_sessions` WHERE id = :id');
$del->bindParam(':id',$_GET['session_id']);
if($del->execute())
	alert('<strong>Success.</strong> You deleted this unit session.','alert-success');
else
	alert('<strong>Couldn\'t delete.</strong> Sorry. <pre>'. print_r($data->errorInfo(), true).'</pre>','alert-error');

redirect_to("acp/user_detail");