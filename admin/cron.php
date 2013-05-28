<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . 'Model/Run.php'; # Study , nothing is echoed yet

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
		continue;
	endif;
	
	// get all session codes that have Branch, Pause, or Email lined up (not ended)
	$dues = $run->getCronDues();
	
	foreach($dues AS $session):
		require_once INCLUDE_ROOT . "Model/RunSession.php";
		
		$run_session = new RunSession($fdb, $run->id, null, $session);
		
		$types = $run_session->getUnit(true); // start looping thru their units. par = $cron = true
		$i++;
		foreach($types AS $type => $nr):
			if(isset($done[$type])):
				$done[$type] += $nr;
			else:
				$done[$type] = $nr;
			endif;
		endforeach;
	endforeach;

endforeach;

$msg = gmdate( 'Y-m-d H:i:s' ) . ' ' . "$i sessions in $r runs were processed. {$done['Email']} emails were sent. {$done['Branch']} branches, {$done['TimeBranch']} time-branches and {$done['Pause']} pauses were evaluated." . "\n";
$msg .= $site->renderAlerts();

error_log( $msg, 3, INCLUDE_ROOT ."admin/cron.log");

echo $msg;