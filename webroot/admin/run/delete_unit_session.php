<?php

$del = $fdb->prepare('DELETE FROM `survey_unit_sessions` WHERE id = :id');
$del->bindParam(':id', $_GET['session_id']);
if($del->execute())
	alert('<strong>Success.</strong> You deleted this unit session.','alert-success');
else
	alert('<strong>Couldn\'t delete.</strong> Sorry. <pre>'. print_r($data->errorInfo(), true).'</pre>','alert-danger');

redirect_to("admin/run/{$run->name}/user_detail");