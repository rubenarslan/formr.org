<?php
    Template::load('header');
    Template::load('acp_nav');
?>	
<h2>cron log</h2>
<p>
	The cron job runs every x minutes, to evaluate whether somebody needs to be sent a mail. This usually happens if a pause is over. It will then skip forward or backward, send emails and shuffle participants, but will stop at surveys and pages, because those should be viewed by the user.
</p>


<?php

$cron_entries = $fdb->prepare("SELECT COUNT(`survey_cron_log`.id) AS count
FROM `survey_cron_log`");

$pagination = new Pagination($cron_entries);
$limits = $pagination->getLimits();

$g_cron = $fdb->prepare("SELECT 
	`survey_cron_log`.id,
	`survey_users`.email,
	`survey_cron_log`.run_id,
	`survey_cron_log`.created,
	`survey_cron_log`.ended - `survey_cron_log`.created AS time_in_seconds,
	`survey_cron_log`.sessions, 
	`survey_cron_log`.skipbackwards, 
	`survey_cron_log`.skipforwards, 
	`survey_cron_log`.pauses, 
	`survey_cron_log`.emails, 
	`survey_cron_log`.shuffles, 
	`survey_cron_log`.errors, 
	`survey_cron_log`.warnings, 
	`survey_cron_log`.notices, 
	`survey_cron_log`.message,

	`survey_runs`.name AS run_name
	
	
FROM `survey_cron_log`

LEFT JOIN `survey_runs`
ON `survey_cron_log`.run_id = `survey_runs`.id
LEFT JOIN `survey_users`
ON `survey_users`.id = `survey_runs`.user_id

ORDER BY `survey_cron_log`.id DESC 
LIMIT $limits;");
$g_cron->bindParam(':user_id',$user->id);
$g_cron->execute() or die(print_r($g_cron->errorInfo(), true));

$cronlogs = array();
while($cronlog = $g_cron->fetch(PDO::FETCH_ASSOC))
{
    $cronlog = array_reverse($cronlog, true); 
	$cronlog['Modules'] = '<small>';
	
	if($cronlog['pauses']>0)
		$cronlog['Modules'] .= $cronlog['pauses'].' <i class="fa fa-pause"></i> ';
	if($cronlog['skipbackwards']>0)
		$cronlog['Modules'] .= 	$cronlog['skipbackwards'].' <i class="fa fa-backward"></i> ';
	if($cronlog['skipforwards']>0)
		$cronlog['Modules'] .= 	$cronlog['skipforwards'].' <i class="fa fa-forward"></i> ';
	if($cronlog['emails']>0)
		$cronlog['Modules'] .= 	$cronlog['emails'].' <i class="fa fa-envelope"></i> ';
	if($cronlog['shuffles']>0)
		$cronlog['Modules'] .= 	$cronlog['shuffles'].' <i class="fa fa-random"></i>';
	$cronlog['Modules'] .=	'</small>';
	$cronlog['took'] = '<small>'.round($cronlog['time_in_seconds']/60, 2). 'm</small>';
	$cronlog['time'] = '<small title="'.$cronlog['created'].'">'.timetostr(strtotime($cronlog['created'])). '</small>';
	$cronlog['Run name'] = $cronlog['run_name'];
	$cronlog['Owner'] = $cronlog['email'];
	
    $cronlog = array_reverse($cronlog, true);
	unset($cronlog['run_name']);
	unset($cronlog['created']);
	unset($cronlog['time_in_seconds']);
	unset($cronlog['skipforwards']);
	unset($cronlog['skipbackwards']);
	unset($cronlog['pauses']);
	unset($cronlog['emails']);
	unset($cronlog['shuffles']);
	unset($cronlog['run_id']);
	unset($cronlog['id']);
	unset($cronlog['email']);
	
	$cronlogs[] = $cronlog;
}
if(!empty($cronlogs)) {
	?>
	<table class='table table-striped table-bordered'>
		<thead><tr>
	<?php
	foreach(current($cronlogs) AS $field => $value):
		if($field=='skipbackwards')
			$field = '<i class="fa fa-backward" title="SkipBackward"></i>';
		elseif($field=='skipforwards')
			$field = '<i class="fa fa-forward" title="SkipForward"></i>';
		elseif($field=='pauses')
			$field = '<i class="fa fa-pause" title="Pause"></i>';
		elseif($field=='emails')
			$field = '<i class="fa fa-envelope" title="Emails attempted to send"></i>';
		elseif($field=='shuffles')
			$field = '<i class="fa fa-random" title="Shuffle"></i>';
		elseif($field=='sessions')
			$field = '<i class="fa fa-users" title="User sessions"></i>';
		elseif($field=='errors')
			$field = '<i class="fa fa-bolt" title="Errors that occurred"></i>';
		elseif($field=='warnings')
			$field = '<i class="fa fa-exclamation-triangle" title="Warnings that occurred"></i>';
		elseif($field=='notices')
			$field = '<i class="fa fa-info-circle" title="Notices that occurred"></i>';
		
	    echo "<th>{$field}</th>";
	endforeach;
	?>
		</tr></thead>
	<tbody>
		<?php
		$tr_class = '';
		
		// printing table rows
		foreach($cronlogs AS $row):
		    foreach($row as $cell):
		        echo "<td>$cell</td>";
			endforeach;

		    echo "</tr>\n";
		endforeach;
	}
		?>

	</tbody></table>
	</div>
	<?php $pagination->render("superadmin/cron_log"); ?>
</div>
	
<?php Template::load('footer');