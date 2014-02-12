<?php
require_once '../../define_root.php';
ob_start();
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . 'Model/Run.php'; # Study , nothing is echoed yet
require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";

set_time_limit(300); # defaults to 30

/// GET ALL RUNS
$g_runs = $fdb->query("SELECT * FROM `survey_runs` WHERE cron_active = 1");
$runs = array();
while($tmp = $g_runs->fetch())
{
	$runs[] = $tmp;
}
$r = 0;

foreach($runs AS $run_data):
	$i = 0;
	$done = array('Pause' => 0,'Email' => 0,'SkipForward' => 0, 'SkipBackward' => 0, 'Shuffle' => 0);

	$r++;
	$created = date('Y-m-d H:i:s');
	$run = new Run($fdb, $run_data['name']);
	if(!$run->valid):
		alert("This run '{$run_data['name']}' caused problems", 'alert-danger');
		continue;
	endif;
	
	// get all session codes that have Branch, Pause, or Email lined up (not ended)
	$dues = $run->getCronDues();
	
	foreach($dues AS $session):
		require_once INCLUDE_ROOT . "Model/RunSession.php";
		
		$run_session = new RunSession($fdb, $run->id, 'cron', $session);
		
		$types = $run_session->getUnit(); // start looping thru their units.
		$i++;
		if($types===false):
			alert("This session '$session' caused problems", 'alert-danger');
			continue 1;
		endif;
		
		foreach($types AS $type => $nr):
			if(isset($done[$type])):
				$done[$type] += $nr;
			else:
				$done[$type] = $nr;
			endif;
		endforeach;
	endforeach;

	$alert_types = $site->alert_types;
	$alerts = $site->renderAlerts();
	$alerts = str_replace('<button type="button" class="close" data-dismiss="alert">&times;</button>', '', $alerts);
	
	$msg = date( 'Y-m-d H:i:s' ) . ' ' . "$i sessions in the run ".$run->name." were processed. {$done['Email']} emails were sent. {$done['SkipForward']} SkipForwards, {$done['SkipBackward']} SkipBackwards, {$done['Shuffle']} shuffles, and {$done['Pause']} pauses were evaluated.<br>" . "\n";
	$msg .= $alerts;

	
	$log = $fdb->prepare("INSERT INTO `survey_cron_log` (run_id, created, ended, sessions, skipforwards, skipbackwards, pauses, emails, shuffles, errors, warnings, notices, message)
												VALUES (:run_id, :created, NOW(), :sessions, :skipforwards, :skipbackwards, :pauses, :emails, :shuffles, :errors, :warnings, :notices, :message)");
	$log->bindParam(':run_id', $run->id);
	$log->bindParam(':created', $created);
	$log->bindParam(':sessions', $i);
	$log->bindParam(':skipforwards', $done['SkipForward']);
	$log->bindParam(':skipbackwards', $done['SkipBackward']);
	$log->bindParam(':pauses', $done['Pause']);
	$log->bindParam(':emails', $done['Email']);
	$log->bindParam(':shuffles', $done['Shuffle']);
	$log->bindParam(':errors', $alert_types['alert-danger']);
	$log->bindParam(':warnings', $alert_types['alert-warning']);
	$log->bindParam(':notices', $alert_types['alert-info']);
	$log->bindParam(':message', $alerts);
	$log->execute();


	echo $msg."<br>";
endforeach;


// error_log( $msg, 3, INCLUDE_ROOT ."tmp/logs/cron.log");

require_once INCLUDE_ROOT . "View/footer.php";

ob_flush();