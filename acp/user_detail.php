<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "admin/admin_header.php";
require_once INCLUDE_ROOT . "view_header.php";
require_once INCLUDE_ROOT . "acp/acp_nav.php";
?>
<h2>user log</h2>

<?php
$g_users = $fdb->query("SELECT 
	`survey_unit_sessions`.id AS session_id,
	`survey_unit_sessions`.session,
	`survey_runs`.name AS run_name,
	`survey_run_units`.position,
	`survey_units`.type AS unit_type,
	`users`.`email`,
	`survey_unit_sessions`.created,
	`survey_unit_sessions`.ended
	
	
FROM `survey_unit_sessions`

LEFT JOIN `survey_units`
ON `survey_unit_sessions`.unit_id = `survey_units`.id
LEFT JOIN `survey_run_units`
ON `survey_unit_sessions`.unit_id = `survey_run_units`.unit_id
LEFT JOIN `survey_runs`
ON `survey_runs`.id = `survey_run_units`.run_id
LEFT JOIN `users`
ON `survey_unit_sessions`.session = `users`.code
ORDER BY `survey_unit_sessions`.id DESC;");

$users = array();
while($userx = $g_users->fetch(PDO::FETCH_ASSOC))
{
	if($userx['unit_type']!= 'Survey') $userx['delete'] = "<a onclick='return confirm(\"Are you sure you want to delete this unit session?\")' href='".WEBROOT."acp/delete_unit_session?session_id={$userx['session_id']}' class='hastooltip' title='Delete this waypoint'><i class='icon-remove'></i></a>";
	else $userx['delete'] =  "<a onclick='return confirm(\"You shouldnt delete survey sessions, you might delete data! REALLY sure?\")' href='".WEBROOT."acp/delete_unit_session?session_id={$userx['session_id']}' class='hastooltip' title='Survey sessions should not be deleted'><i class='icon-remove'></i></a>";
	
	$userx['email'] = "<small title=\"{$userx['session']}\">{$userx['email']}</small>";
	$userx['created'] = "<small>{$userx['created']}</small>";
	$userx['run_name'] = "<span>{$userx['run_name']} <span class='hastooltip' title='Current position in run'>({$userx['position']})</span></small>";
	unset($userx['session']);
	unset($userx['position']);
#	$user['body'] = "<small title=\"{$user['body']}\">". substr($user['body'],0,50). "…</small>";
	
	$users[] = $userx;
}
if(!empty($users)) {
	?>
	<table class='table table-striped'>
		<thead><tr>
	<?php
	foreach(current($users) AS $field => $value):
	    echo "<th>{$field}</th>";
	endforeach;
	?>
		</tr></thead>
	<tbody>
		<?php
		// printing table rows
		foreach($users AS $row):
		    echo "<tr>";

		    // $row is array... foreach( .. ) puts every element
		    // of $row to $cell variable
		    foreach($row as $cell):
		        echo "<td>$cell</td>";
			endforeach;

		    echo "</tr>\n";
		endforeach;
	}
		?>

	</tbody></table>

<?php
require_once INCLUDE_ROOT . "view_footer.php";