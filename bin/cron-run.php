#!/usr/bin/php
<?php

require_once dirname(__FILE__) . '/../setup.php';

// log to formr log file
function cron_log($message) {
	$message = date('Y-m-d H:i:s') . ' ' . $message . "\n";
	/*
	$cron_logfile = INCLUDE_ROOT . 'tmp/logs/cron.log';
	return error_log($message, 3, $cron_logfile);
	 */
	// echo to STDOUT instead
	echo $message;
}

function cron_parse_executed_types($types) {
	$str = '';
	foreach ($types as $key => $value) {
		$str .= " {$value} {$key}s,";
	}
	return $str;
}

function cron_lock_exists($lockfile, $start_date) {
	if (file_exists($lockfile)) {
		$started = file_get_contents($lockfile);
		cron_log("Cron overlapped. Started: $started, Overlapped: $start_date");

		// hack to delete $lockfile if cron hangs for more that 30 mins
		if ((strtotime($started) + ((int)Config::get('cron.ttl_lockfile') * 60)) < time()) {
			cron_log("Forced delete of $lockfile");
			unlink($lockfile);
			return false;
		}
		return true;
	}
	return false;
}

function cron_cleanup() {
	global $lockfile;

	if (file_exists($lockfile)) {
		unlink($lockfile);
	}
}

$opts = getopt('n:');
if (empty($opts['n'])) {
	echo "Run name not specified";
	exit(1);
}

$site = Site::getInstance();
$user = new User(DB::getInstance(), null, null);
$user->cron = true;
$name = $opts['n'];
$run = new Run(DB::getInstance(), $name);

$start_date = date('r');
$start_time = microtime(true);
$lockfile = INCLUDE_ROOT . "tmp/cron-{$name}.lock";

if (!$run->valid) {
	echo "Run not found";
	exit(1);
}

if (cron_lock_exists($lockfile, $start_date)) {
	exit(0);
}

cron_log('----------');
cron_log("cron-run call start for {$run->name}");

// Lock cron
file_put_contents($lockfile, $start_date);
register_shutdown_function('cron_cleanup');

// get all session codes that have Branch, Pause, or Email lined up (not ended)
$dues = $run->getCronDues();
$done = array();
$i = 0;
// Foreach session, execute all units
$run->getOwner();
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

$msg = "$i sessions in the run " . $run->name . " were processed. {$executed_types}";
cron_log($msg);
if ($site->alerts) {
	cron_log("\n<alerts>\n" . $site->renderAlerts() . "\n</alerts>");
}

// log execution time
$exec_time = microtime(true) - $start_time;
$lasted = $exec_time > 60 ? ceil($exec_time / 60) . ' minutes' : ceil($exec_time) . ' seconds';
cron_log("Cron ran for {$lasted}");

// cleanup
cron_cleanup();
cron_log("cron-run call end for {$run->name}");
exit(0);

