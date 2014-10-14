<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
$js = '<script src="'.WEBROOT.'assets/run_users.js"></script>';
require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
require_once INCLUDE_ROOT . "Model/Pagination.php";
?>
<div class="row">
	<div class="col-md-12">
		<h2>log of user activity in this run</h2>
		<p class="lead">Here you can see users' history of participation, i.e. when they got to certain point in a study, how long they staid at each station and so forth. Earliest participants come first.</p>
<?php

$search = '';
$querystring = array();
$position_lt = '=';
if(isset($_GET['session']) OR isset($_GET['position'])):
	if(isset($_GET['session']) AND trim($_GET['session'])!=''):
		$_GET['session'] = str_replace("…","",$_GET['session']);
		$search .= 'AND `survey_run_sessions`.session LIKE :session ';
		$search_session = $_GET['session'] . "%";
		$querystring['session'] = $_GET['session'];
	endif;
	if(isset($_GET['position']) AND trim($_GET['position'])!=''):
		if(isset($_GET['position']) AND in_array($_GET['position_lt'], array('=','>','<'))) $position_lt = $_GET['position_lt'];

		$search .= 'AND `survey_run_sessions`.position '.$position_lt.' :position ';
		$search_position = $_GET['position'];
		$querystring['position_lt'] = $position_lt;
		$querystring['position'] = $_GET['position'];
	endif;
endif;


$user_nr = $fdb->prepare("SELECT COUNT(`survey_unit_sessions`.id) AS count
	FROM `survey_unit_sessions`

	LEFT JOIN `survey_run_sessions`
	ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id

	WHERE `survey_run_sessions`.run_id = :run_id
	$search
");
$user_nr->bindValue(':run_id',$run->id);
if(isset($search_session)):
	$user_nr->bindValue(':session',$search_session);
endif;
if(isset($search_position)):
	$user_nr->bindValue(':position',$search_position);
endif;

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
$search
ORDER BY `survey_run_sessions`.id DESC,`survey_unit_sessions`.id ASC
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
session_over($site, $user);

if(!empty($users)):
	?>
	<div class="row col-md-12">
		<form action="<?=WEBROOT.'admin/run/'.$run->name.'/user_detail'?>" method="get" accept-charset="utf-8">
		
		<div class="row">
		  <div class="col-lg-3">
		    <div class="input-group">
			  <span class="input-group-addon"><i class="fa fa-user"></i></span>
			  <input type="search" placeholder="Session key" name="session" class="form-control" value="<?=isset($_GET['session'])?h($_GET['session']):'';?>">
		
		    </div><!-- /input-group -->
		  </div><!-- /.col-lg-6 -->
		  <div class="col-lg-3">
		    <div class="input-group">
			  <span class="input-group-addon" title="This refers to the user's current position!"><i class="fa fa-flag-checkered"></i></span>
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
	if(!empty($querystring)) $append = "?".http_build_query($querystring)."&";
	else $append = '';
	$pagination->render("admin/run/".$run->name."/user_detail".$append);
	
	
	endif;
	?>
	</div>
</div>
		
<?php
require_once INCLUDE_ROOT . "View/footer.php";