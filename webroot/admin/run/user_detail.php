<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
require_once INCLUDE_ROOT . "Model/Pagination.php";
?>
<div class="row">
	<div class="col-md-12">
		<h2>log of user activity in this run</h2>
		<p class="lead">Here you can see users' history of participation, i.e. when they got to certain point in a study, how long they staid at each station and so forth. Earliest participants come first.</p>
<?php

$user_nr = $fdb->prepare("SELECT COUNT(`survey_unit_sessions`.id) AS count
	FROM `survey_unit_sessions`

	LEFT JOIN `survey_run_sessions`
	ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id

	WHERE `survey_run_sessions`.run_id = :run_id
");
$user_nr->bindValue(':run_id',$run->id);

$pagination = new Pagination($user_nr, 400, true);
$limits = $pagination->getLimits();

$g_users = $fdb->prepare("SELECT 
	`survey_run_sessions`.session,
	`survey_unit_sessions`.id AS session_id,
	`survey_runs`.name AS run_name,
	`survey_run_units`.position,
	`survey_units`.type AS unit_type,
	`survey_unit_sessions`.created,
	`survey_unit_sessions`.ended
	
	
FROM `survey_unit_sessions`

LEFT JOIN `survey_run_sessions`
ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
LEFT JOIN `survey_units`
ON `survey_unit_sessions`.unit_id = `survey_units`.id
LEFT JOIN `survey_run_units`
ON `survey_unit_sessions`.unit_id = `survey_run_units`.unit_id
LEFT JOIN `survey_runs`
ON `survey_runs`.id = `survey_run_units`.run_id
WHERE `survey_run_sessions`.run_id = :run_id
ORDER BY `survey_run_sessions`.id DESC,`survey_unit_sessions`.id ASC
LIMIT $limits;");
$g_users->bindParam(':run_id',$run->id);
$g_users->execute();

$users = array();
while($userx = $g_users->fetch(PDO::FETCH_ASSOC))
{
	$userx['Unit in Run'] = $userx['unit_type']. " <span class='hastooltip' title='position in run {$userx['run_name']} '>({$userx['position']})</span>";
	$userx['Session'] = "<small><abbr class='abbreviated_session' title='Click to show the full session' data-full-session=\"{$userx['session']}\">".mb_substr($userx['session'],0,10)."…</abbr></small>";
	$userx['entered'] = "<small>{$userx['created']}</small>";
	$staid = ($userx['ended'] ? strtotime($userx['ended']) : time() ) -strtotime($userx['created']);
	$userx['staid'] = "<small title='$staid seconds'>".timetostr(time()+$staid)."</small>";
	$userx['left'] = "<small>{$userx['ended']}</small>";
	if($userx['unit_type']!= 'Survey') 
		$userx['delete'] = "<a onclick='return confirm(\"Are you sure you want to delete this unit session?\")' href='".WEBROOT."admin/run/{$userx['run_name']}/delete_unit_session?session_id={$userx['session_id']}' class='hastooltip' title='Delete this waypoint'><i class='fa fa-times'></i></a>";
	else 
		$userx['Delete'] =  "<a onclick='return confirm(\"You shouldnt delete survey sessions, you might delete data! REALLY sure?\")' href='".WEBROOT."admin/run/{$userx['run_name']}/delete_unit_session?session_id={$userx['session_id']}' class='hastooltip' title='Survey sessions should not be deleted'><i class='fa fa-times'></i></a>";
	
	unset($userx['session']);
	unset($userx['session_id']);
	unset($userx['run_name']);
	unset($userx['unit_type']);
	unset($userx['position']);
#	$user['body'] = "<small title=\"{$user['body']}\">". substr($user['body'],0,50). "…</small>";
	
	$users[] = $userx;
}
if(!empty($users)):
	?>
	<table class='table'>
		<thead><tr>
	<?php
	foreach(current($users) AS $field => $value):
		if($field == 'created' OR $field == 'ended')
			continue;
	    echo "<th>{$field}</th>";
	endforeach;
	?>
		</tr></thead>
	<tbody>
		<?php
		$last_ended = $last_user = $continued = $user_class = '';
		
		// printing table rows
		foreach($users AS $row):
			if($row['Session']!==$last_user): // next user
				$user_class = ($user_class=='') ? 'alternate' : '';
				$last_user = $row['Session'];
			elseif(round((strtotime($row['created']) - $last_ended)/30)==0): // same user
				$continued = ' immediately_continued';
			endif;
			$last_ended = strtotime($row['created']);
			
			unset($row['created']);
			unset($row['ended']);
			
			
			echo '<tr class="'.$user_class.$continued.'">';
			$continued = '';
		    // $row is array... foreach( .. ) puts every element
		    // of $row to $cell variable
		    foreach($row as $cell):
		        echo "<td>$cell</td>";
			endforeach;

		    echo "</tr>\n";
		endforeach;
			?>
		</tbody></table>
	<?php
	$pagination->render("admin/run/".$run->name."/user_detail");
	
	endif;
	?>
	</div>
</div>
		
<?php
require_once INCLUDE_ROOT . "View/footer.php";