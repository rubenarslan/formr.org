<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
require_once INCLUDE_ROOT . "Model/Pagination.php";
?>
<div class="row">
	<div class="col-md-12">
		<h1>user overview</h1>
		<p class="lead">Here you can see users' progress (on which station they currently are).
			If you're not happy with their progress, you can send manual reminders, <a href="<?=WEBROOT.'admin/run/'.$run->name.'/edit_reminder'?>">customisable here</a>. <br>You can also shove them to a different position in a run if they veer off-track. </p>
			<p>Participants who have been stuck at the same survey, external link or email for 2 days or more are highlighted in yellow at the top. Being stuck at an email module usually means that the user somehow ended up there without a valid email address, so that the email cannot be sent. Being stuck at a survey or external link usually means that the user interrupted the survey/external part before completion, you probably want to remind them manually (if you have the means to do so).</p>
	<?php
	

	$user_nr = $fdb->prepare("SELECT COUNT(`survey_run_sessions`.id) AS count
		FROM `survey_run_sessions`
		WHERE `survey_run_sessions`.run_id = :run_id;");
	$user_nr->bindValue(':run_id',$run->id);

	$pagination = new Pagination($user_nr, 200, true);
	$limits = $pagination->getLimits();
	
	$g_users = $fdb->prepare("SELECT 
		`survey_run_sessions`.id AS run_session_id,
		`survey_run_sessions`.session,
		`survey_run_sessions`.position,
		`survey_run_sessions`.last_access,
		`survey_run_sessions`.created,
		`survey_runs`.name AS run_name,
		`survey_units`.type AS unit_type,
		`survey_run_sessions`.last_access,
		(`survey_units`.type IN ('Survey','External','Email') AND DATEDIFF(NOW(), `survey_run_sessions`.last_access) >= 2) AS hang
	
	
	FROM `survey_run_sessions`

	LEFT JOIN `survey_runs`
	ON `survey_run_sessions`.run_id = `survey_runs`.id
	
	LEFT JOIN `survey_run_units`
	ON `survey_run_sessions`.position = `survey_run_units`.position AND `survey_run_units`.run_id = `survey_run_sessions`.run_id

	LEFT JOIN `survey_units`
	ON `survey_run_units`.unit_id = `survey_units`.id

	WHERE `survey_run_sessions`.run_id = :run_id

	ORDER BY hang DESC, `survey_run_sessions`.last_access DESC
	LIMIT $limits;");
	$g_users->bindParam(':run_id',$run->id);
	$g_users->execute();

	$users = array();
	while($userx = $g_users->fetch(PDO::FETCH_ASSOC))
	{
		$userx['Run position'] = "<span class='hastooltip' title='Current position in run'>({$userx['position']}</span> – <small>{$userx['unit_type']})</small>";
		$userx['Session'] = "<small><abbr class='abbreviated_session' title='Click to show the full session' data-full-session=\"{$userx['session']}\">".mb_substr($userx['session'],0,10)."…</abbr></small>";
		$userx['Created'] = "<small>{$userx['created']}</small>";
		$userx['Last Access'] = "<small class='hastooltip' title='{$userx['last_access']}'>".timetostr(strtotime($userx['last_access']))."</small>";
		$userx['Action'] = "
			<form class='form-inline' action='".WEBROOT."admin/run/{$userx['run_name']}/send_to_position?session={$userx['session']}' method='post'>
			<span class='input-group' style='width:160px'>
				<span class='input-group-btn'>
					<button type='submit' class='btn hastooltip'
					title='Send this user to that position'><i class='fa fa-hand-o-right'></i></button>
				</span>
				<input type='number' name='new_position' value='{$userx['position']}' class='form-control'>
				<span class='input-group-btn'>
					<a class='btn hastooltip' href='".WEBROOT."admin/run/{$userx['run_name']}/remind?run_session_id={$userx['run_session_id']}&amp;session={$userx['session']}' 
					title='Remind this user'><i class='fa fa-bullhorn'></i></a>
				</span>
			</span>
		</form>";
	
		unset($userx['session']);
		unset($userx['position']);
		unset($userx['run_name']);
		unset($userx['run_session_id']);
		unset($userx['unit_type']);
		unset($userx['last_access']);
		unset($userx['last_access_days']);
		unset($userx['created']);
	#	$user['body'] = "<small title=\"{$user['body']}\">". substr($user['body'],0,50). "…</small>";
	
		$users[] = $userx;
	}
	
	session_over($site, $user);
	
	if(!empty($users)):
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
			?>
		</tbody></table>
	<?php
	$pagination->render("admin/run/".$run->name."/user_overview");
	
	endif;
	?>
	</div>
</div>
		

<?php
require_once INCLUDE_ROOT . "View/footer.php";