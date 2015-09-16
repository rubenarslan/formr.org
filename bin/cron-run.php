<?php

require_once dirname(__FILE__) . '/../define_root.php';

// log to formr log file
function cron_log($message) {
	$cron_logfile = INCLUDE_ROOT . 'tmp/logs/cron.log';
	$message = date('Y-m-d H:i:s') . ' ' . $message . "\n";
	return error_log($message, 3, $cron_logfile);
}

function cron_parse_executed_types($types) {
	$str = '';
	foreach ($types as $key => $value) {
		$str .= " {$value} {$key}s,";
	}
	return $str;
}

$opts = getopt('n:');
if (empty($opts['n'])) {
	echo "Run name not specified";
	exit(1);
}

$site = Site::getInstance();
$name = $opts['n'];
$run = new Run(DB::getInstance(), $name);
if (!$run->valid) {
	echo "Run not found";
	exit(1);
}

cron_log("cron-run call start for {$run->name}");
// get all session codes that have Branch, Pause, or Email lined up (not ended)
$dues = $run->getCronDues();
$done = array();
$i = 0;
// Foreach session, execute all units
foreach ($dues as $session) {
	$run_session = new RunSession(DB::getInstance(), $run->id, 'cron', $session, $run);
	$types = $run_session->getUnit(); // start looping thru their units.
	$i++;

	if ($types === false) {
		alert("This session '$session' caused problems", 'alert-danger');
		continue;
	}

	foreach ($types as $type => $nr) {
		if (!isset($done[$type])) {
			$done[$type] = 0;
		}
		$done[$type] += $nr;
	}
}

$executed_types = cron_parse_executed_types($done);

$msg = date('Y-m-d H:i:s') . ' ' . "$i sessions in the run " . $run->name . " were processed. {$executed_types}.";
cron_log($msg);
cron_log("cron-run call end for {$run->name}");
exit(0);

