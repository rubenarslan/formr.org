<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
$js = '<script src="'.WEBROOT.'assets/run.js"></script>';
require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
require_once INCLUDE_ROOT . "Model/Pagination.php";

$search = '';
$position_lt = '=';
if(isset($_GET['session']) OR isset($_GET['position'])):
	if(isset($_GET['session']) AND trim($_GET['session'])!=''):
		$_GET['session'] = str_replace("…","",$_GET['session']);
		$search .= 'AND `survey_run_sessions`.session LIKE :session ';
		$search_session = $_GET['session'] . "%";
	endif;
	if(isset($_GET['position']) AND trim($_GET['position'])!=''):
		if(isset($_GET['position']) AND in_array($_GET['position_lt'], array('=','>','<'))) $position_lt = $_GET['position_lt'];

		$search .= 'AND `survey_run_sessions`.position '.$position_lt.' :position ';
		$search_position = $_GET['position'];
	endif;
endif;

$user_nr = $fdb->prepare("SELECT COUNT(`survey_run_sessions`.id) AS count
	FROM `survey_run_sessions`
	WHERE `survey_run_sessions`.run_id = :run_id
	$search;");
$user_nr->bindValue(':run_id',$run->id);
if(isset($search_session)):
	$user_nr->bindValue(':session',$search_session);
endif;
if(isset($search_position)):
	$user_nr->bindValue(':position',$search_position);
endif;

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
$search
ORDER BY hang DESC, `survey_run_sessions`.last_access DESC
LIMIT $limits;");
$g_users->bindParam(':run_id',$run->id);
if(isset($search_session)):
	$g_users->bindValue(':session',$search_session);
endif;
if(isset($search_position)):
	$g_users->bindValue(':position',$search_position);
endif;

$g_users->execute();

$users = array();
while($userx = $g_users->fetch(PDO::FETCH_ASSOC))
{
	$userx['Run position'] = "<span class='hastooltip' title='Current position in run'>({$userx['position']}</span> – <small>{$userx['unit_type']})</small>";
	$userx['Session'] = "<small><abbr class='abbreviated_session' title='Click to show the full session' data-full-session=\"{$userx['session']}\">".mb_substr($userx['session'],0,10)."…</abbr></small>";
	$userx['Created'] = "<small>{$userx['created']}</small>";
	$userx['Last Access'] = "<small class='hastooltip' title='{$userx['last_access']}'>".timetostr(strtotime($userx['last_access']))."</small>";
	$userx['Action'] = "
		<form class='form-inline form-ajax' action='".WEBROOT."admin/run/{$userx['run_name']}/ajax_send_to_position?session={$userx['session']}' method='post'>
		<span class='input-group' style='width:160px'>
			<span class='input-group-btn'>
				<button type='submit' class='btn hastooltip'
				title='Send this user to that position'><i class='fa fa-hand-o-right'></i></button>
			</span>
			<input type='number' name='new_position' value='{$userx['position']}' class='form-control'>
			<span class='input-group-btn'>
				<a class='btn hastooltip link-ajax' href='".WEBROOT."admin/run/{$userx['run_name']}/ajax_remind?run_session_id={$userx['run_session_id']}&amp;session={$userx['session']}' 
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

?>
<div class="row">
	<div class="col-md-12">
		<h1>user overview <small><?=$pagination->maximum?> users</small></h1>
		<p class="lead">Here you can see users' progress (on which station they currently are).
			If you're not happy with their progress, you can send manual reminders, <a href="<?=WEBROOT.'admin/run/'.$run->name.'/edit_reminder'?>">customisable here</a>. <br>You can also shove them to a different position in a run if they veer off-track. </p>
			<p>Participants who have been stuck at the same survey, external link or email for 2 days or more are highlighted in yellow at the top. Being stuck at an email module usually means that the user somehow ended up there without a valid email address, so that the email cannot be sent. Being stuck at a survey or external link usually means that the user interrupted the survey/external part before completion, you probably want to remind them manually (if you have the means to do so).</p>
			<div class="row col-md-12">
				<form action="<?=WEBROOT.'admin/run/'.$run->name.'/user_overview'?>" method="get" accept-charset="utf-8">
				
				<div class="row">
				  <div class="col-lg-3">
				    <div class="input-group">
					  <span class="input-group-addon"><i class="fa fa-user"></i></span>
					  <input type="search" placeholder="Session key" name="session" class="form-control" value="<?=isset($_GET['session'])?h($_GET['session']):'';?>">
				
				    </div><!-- /input-group -->
				  </div><!-- /.col-lg-6 -->
				  <div class="col-lg-3">
				    <div class="input-group">
					  <span class="input-group-addon"><i class="fa fa-flag-checkered"></i></span>
						<input type="number" placeholder="Position" name="position" class="form-control round_right" value="<?=isset($_GET['position'])?h($_GET['position']):'';?>">
						
				    </div><!-- /input-group -->
				  </div><!-- /.col-lg-6 -->
				  
				  <div style="width:65px; float:left">
					<select class="form-control" name="position_lt">
						<option value="=" <?=($position_lt=='=')?'selected':'';?>>=</option>
						<option value="&lt;" <?=($position_lt=='<')?'selected':'';?>>&lt;</option>
						<option value="&gt;" <?=($position_lt=='>')?'selected':'';?>>&gt;</option>
					</select>
					  
				  </div>
				  
				  
				  <div class="col-lg-1">
				    <div class="input-group">
						<input type="submit" value="Search" class="btn">
						
				    </div><!-- /input-group -->
				  </div><!-- /.col-lg-6 -->
				</div><!-- /.row -->
				
				</form>
			</div>
	<?php
	
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