#!/usr/bin/php
<?php

require_once dirname(__FILE__) . '/../define_root.php';

// Set maximum execution time to 9 minutes as cron runs every 10 minutes. (There should be better way to do this)
$start_time = microtime(true);
$max_exec_time = (int)Config::get('cron.ttl_cron') * 60;
$intercept_if_expired = (int)Config::get('cron.intercept_if_expired');
set_time_limit($max_exec_time);

// Define vars
$start_date = date('r');
$lockfile = INCLUDE_ROOT . 'tmp/cron.lock';

/**  Define cron specific functions */
// log to formr log file
function cron_log($message, $cron_log = true) {
	$cron_logfile = INCLUDE_ROOT . 'tmp/logs/cron.log';
	$logfile = INCLUDE_ROOT . 'tmp/logs/errors.log';
	$message = date('Y-m-d H:i:s') . ' ' . $message . "\n";
	if ($cron_log) {
		return error_log($message, 3, $cron_logfile);
	}
	error_log($message, 3, $logfile);
}

// clean up on shutdown
function cron_cleanup($interrupt = null) {
	global $lockfile, $start_time, $max_exec_time;
	$exec_time = microtime(true) - $start_time;
	if ($exec_time > $max_exec_time) {
		$msg = "[$interrupt] Cron exceeded or reached set maximum script execution time of $max_exec_time secs.";
		cron_log($msg);
	}

	if (file_exists($lockfile)) {
		unlink($lockfile);
		cron_log("Cronfile cleanup complete");
	}
}

function cron_parse_executed_types($types) {
	$str = '';
	foreach ($types as $key => $value) {
		$str .= " {$value} {$key}s,";
	}
	return $str;
}

function cron_interrupt($signo) {
	switch ($signo) {
		// Set terminated flag to be able to terminate program securely
		// to prevent from terminating in the middle of the process
		// Use Ctrl+C to send interruption signal to a running program
		case SIGINT:
		case SIGTERM:
			cron_cleanup('SIGINT|SIGTERM');
		break;
		// @example: $ kill -s SIGUSR1 <pid>
		case SIGUSR1:
			cron_cleanup('SIGUSR1');
		break;
	}
}

// Register signal handlers that should be able to kill the cron in case some other weird shit happens apart from cron exiting cleanly
if (extension_loaded('pcntl')) {
	pcntl_signal(SIGINT, 'cron_interrupt');
	pcntl_signal(SIGTERM, 'cron_interrupt');
	pcntl_signal(SIGUSR1, 'cron_interrupt');
}

// even though the cronjobs are supposed to run only 6 min and are spaced 7 min, there seem to be problems due to overlapping CJs
// the lockfile is supposed to fix this
if (file_exists($lockfile)) {
	global $start_date;
	$started = file_get_contents($lockfile);
	cron_log("Cron overlapped. Started: $started, Overlapped: $start_date");

	// hack to delete $lockfile if cron hangs for more that 30 mins
	if ((strtotime($started) + ((int)Config::get('cron.ttl_lockfile') * 60)) < time()) {
		cron_log("Forced delete of $lockfile");
		unlink($lockfile);
	}
	exit(0);
}

// Lock cron
file_put_contents($lockfile, $start_date);
register_shutdown_function('cron_cleanup');

/** Do the Work */
cron_log("Cron started .... {$start_date}", true);

// Global required variables
$site = Site::getInstance();
$fdb = DB::getInstance();
$user = new User($fdb, null, null);
$user->cron = true;

// Wrap in a try catch just in case because we can't see shit
try {
	// Get all runs
	$runs = $fdb->select('name')->from('survey_runs')->where('cron_active = 1')->order('RAND')->fetchAll();

	$r = 0;
	foreach ($runs as $run_data):
		$i = 0;
		$r++;
		$done = array('Pause' => 0, 'Email' => 0, 'SkipForward' => 0, 'SkipBackward' => 0, 'Shuffle' => 0);
		$created = date('Y-m-d H:i:s');

		$run = new Run($fdb, $run_data['name']);
		if (!$run->valid) {
			alert("This run '{$run_data['name']}' caused problems", 'alert-danger');
			continue;
		}

		// Execute the cron of each log as background process
		$script = dirname(__FILE__) . '/cron-run.php';
		$stdout = get_log_file("cron-run-{$run->name}.log");
		$command = "php $script -n {$run->name} > {$stdout} 2>&1";
		exec($command, $output, $status);
		if ($status != 0) {
			cron_log("Command '{$command}' exited with status {$status}. Output: " . print_r($output, 1));
		}
		if ($intercept_if_expired && microtime(true) - $start_time > $max_exec_time) {
			throw new Exception("Cron Intercepted! Started at: $start_date, Intercepted at: " . date('r'));
		}
	endforeach;
} catch (Exception $e) {
	cron_log('Cron [Exception]: ' . $e->getMessage());
	cron_log('Cron [Exception]: ' . $e->getTraceAsString());
}

$user->cron = false;

$minutes = round((microtime(true) - $start_time) / 60, 3);
$end_date = date('r');
cron_log("Cron ended .... {$end_date}. Took ~{$minutes} minutes", true);
// Do cleanup just in case
cron_cleanup();
