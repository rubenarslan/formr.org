<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
?>
<h1>user overview</h1>

<?php
$g_users = $fdb->prepare("SELECT 
	`survey_run_sessions`.id AS run_session_id,
	`survey_run_sessions`.session,
	`survey_run_sessions`.position,
	`survey_run_sessions`.last_access,
	`survey_run_sessions`.created,
	`survey_runs`.name AS run_name,
	`survey_units`.type AS unit_type,
	`users`.`email`,
	DATEDIFF(NOW(), `survey_run_sessions`.last_access) AS last_access_days,
	(`survey_units`.type IN ('Survey','External') AND DATEDIFF(NOW(), `survey_run_sessions`.last_access) >= 2) AS hang
	
	
FROM `survey_run_sessions`

LEFT JOIN `survey_run_units`
ON `survey_run_sessions`.position = `survey_run_units`.position AND `survey_run_units`.run_id = `survey_run_sessions`.run_id

LEFT JOIN `survey_units`
ON `survey_run_units`.unit_id = `survey_units`.id

LEFT JOIN `survey_runs`
ON `survey_run_sessions`.run_id = `survey_runs`.id 

LEFT JOIN `users`
ON `survey_run_sessions`.session = `users`.code

WHERE `survey_runs`.name = :run_name

ORDER BY hang DESC, `survey_run_sessions`.last_access DESC;");
$g_users->bindParam(':run_name',$run->name);
$g_users->execute();

$users = array();
while($userx = $g_users->fetch(PDO::FETCH_ASSOC))
{
	$userx['Run position'] = "<span class='hastooltip' title='Current position in run'>({$userx['position']}</span> – <small>{$userx['unit_type']})</small>";
	$userx['Email'] = "<small title=\"{$userx['session']}\">{$userx['email']}</small>";
	$userx['Created'] = "<small>{$userx['created']}</small>";
	$userx['Last Access'] = "<small class='hastooltip' title='{$userx['last_access']}'>{$userx['last_access_days']} days ago</small>";
	$userx['Action'] = "
		<form class='form-inline' action='".WEBROOT."admin/run/{$userx['run_name']}/send_to_position?session={$userx['session']}' method='post'>
		<span class='input-append input-prepend'>
	<button type='submit' class='btn hastooltip'
	title='Send this user to that position'><i class='icon-hand-right'></i></button>
		<input type='number' name='new_position' value='{$userx['position']}' class='span1'>
		<a class='btn hastooltip' href='".WEBROOT."admin/run/{$userx['run_name']}/remind?run_session_id={$userx['run_session_id']}&session={$userx['session']}&email={$userx['email']}' 
		title='Remind this user using the last email in the run'><i class='icon-bullhorn'></i></a>
		
		</span></form>";
	
	unset($userx['session']);
	unset($userx['position']);
	unset($userx['run_name']);
	unset($userx['run_session_id']);
	unset($userx['unit_type']);
	unset($userx['last_access']);
	unset($userx['last_access_days']);
	unset($userx['created']);
	unset($userx['email']);
#	$user['body'] = "<small title=\"{$user['body']}\">". substr($user['body'],0,50). "…</small>";
	
	$users[] = $userx;
}
if(!empty($users)) {
	?>
	<table class='table table-striped'>
		<thead><tr>
	<?php
	foreach(current($users) AS $field => $value):
		if($field != 'hang')
		    echo "<th>{$field}</th>";
	endforeach;
	?>
		</tr></thead>
	<tbody>
		<?php
		// printing table rows
		foreach($users AS $row):
			if($row['hang'])
				echo '<tr class="warning">';
			else
			    echo "<tr>";
			unset($row['hang']);

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
require_once INCLUDE_ROOT . "View/footer.php";