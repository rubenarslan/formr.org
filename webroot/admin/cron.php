<?php
require_once '../../define_root.php';
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . 'Model/Run.php'; # Study , nothing is echoed yet

set_time_limit(300); # defaults to 30

/// GET ALL RUNS
$g_runs = $fdb->query("SELECT * FROM `survey_runs` WHERE cron_active = 1");
$runs = array();
while($tmp = $g_runs->fetch())
{
	$runs[] = $tmp;
}
$i = 0;
$r = 0;
$done = array('Pause' => 0,'Email' => 0,'Branch' => 0, 'TimeBranch' => 0);

foreach($runs AS $run_data):
	$r++;
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

endforeach;

$msg = date( 'Y-m-d H:i:s' ) . ' ' . "$i sessions in $r runs were processed. {$done['Email']} emails were sent. {$done['Branch']} branches, {$done['TimeBranch']} time-branches and {$done['Pause']} pauses were evaluated." . "\n";
$msg .= $site->renderAlerts();

error_log( $msg, 3, INCLUDE_ROOT ."tmp/logs/cron.log");

echo $msg;