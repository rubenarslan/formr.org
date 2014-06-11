<?php
require_once '../../define_root.php';
$start_cron_time = microtime(true);
// even though the cronjobs are supposed to run only 6 min and are spaced 7 min, there seem to be problems due to overlapping CJs
// the lockfile is supposed to fix this
$lockfilepath = INCLUDE_ROOT.'/tmp/cron.lock';
if(file_exists($lockfilepath)) {
  die("Cronjob still running.");
}
file_put_contents($lockfilepath,'');
register_shutdown_function(create_function('', "unlink('{$lockfilepath}');")); 
set_time_limit(360); # defaults to 30
ob_start();
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . 'Model/Run.php';
require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
session_over($site, $user);

$user->cron = true;

/// GET ALL RUNS
$g_runs = $fdb->query("SELECT * FROM `survey_runs` WHERE cron_active = 1 ORDER BY RAND();");
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
		
		if(microtime(true) - $start_cron_time > 60*6):
			echo "within-Cronjob interrupted after running ". (microtime(true) - $start_cron_time) . " seconds<br>";
			break;
		endif;
	endforeach;

	$alert_types = $site->alert_types;
	$alerts = $site->renderAlerts();
	$alerts = str_replace('<button type="button" class="close" data-dismiss="alert">&times;</button>', '', $alerts);
	
	$msg = date( 'Y-m-d H:i:s' ) . ' ' . "$i sessions in the run ".$run->name." were processed. {$done['Email']} emails were sent. {$done['SkipForward']} SkipForwards, {$done['SkipBackward']} SkipBackwards, {$done['Shuffle']} shuffles, and {$done['Pause']} pauses ended.<br>" . "\n";
	$msg .= $alerts;
	unset($done["Page"]);
if(array_sum($done) > 0 OR array_sum($alert_types) > 0):	
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
	
endif;

	echo $msg."<br>";
	if(microtime(true) - $start_cron_time > 60 * 6):
		echo "Cronjob interrupted after running ". (microtime(true) - $start_cron_time) . " seconds";
		break;
	endif;
endforeach;


// error_log( $msg, 3, INCLUDE_ROOT ."tmp/logs/cron.log");
$user->cron = false;

require_once INCLUDE_ROOT . "View/footer.php";

ob_flush();
// execute code