#!/usr/bin/php
<?php

require_once dirname(__FILE__) . '/../setup.php';

/**  Define cron specific functions */

// Register signal handlers that should be able to kill the cron in case some other weird shit happens apart from cron exiting cleanly
$logfile = get_log_file('cron.log');
if (extension_loaded('pcntl')) {
	pcntl_signal(SIGINT, 'cron_interrupt');
	pcntl_signal(SIGTERM, 'cron_interrupt');
	pcntl_signal(SIGUSR1, 'cron_interrupt');
}

// log to formr log file
function cron_log($message, $file = null) {
	$message = date('Y-m-d H:i:s') . ' ' . $message . "\n";
	if ($file !== null) {
		return error_log($message, 3, $file);
	}
	// else echo to STDOUT instead
	echo $message;
}

// clean up on shutdown
function cron_cleanup($interrupt = null) {
	global $lockfile, $start_time, $max_exec_time, $logfile;
	if ($start_time && $max_exec_time) {
		$exec_time = microtime(true) - $start_time;
		if ($exec_time > $max_exec_time) {
			$msg = "[$interrupt] Cron exceeded or reached set maximum script execution time of $max_exec_time secs.";
			cron_log($msg, $logfile);
		}
	}

	if (file_exists($lockfile)) {
		unlink($lockfile);
		cron_log("Cronfile cleanup complete", $logfile);
	}
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

function cron_parse_executed_types($types) {
	$str = '';
	foreach ($types as $key => $value) {
		$str .= " {$value} {$key}s,";
	}
	return $str;
}

function cron_lock_exists($lockfile, $start_date, $logfile = null) {
	if (file_exists($lockfile)) {
		$started = file_get_contents($lockfile);
		cron_log("Cron overlapped. Started: $started, Overlapped: $start_date", $logfile);

		// hack to delete $lockfile if cron hangs for more that 30 mins
		if ((strtotime($started) + ((int)Config::get('cron.ttl_lockfile') * 60)) < time()) {
			cron_log("Forced delete of $lockfile", $logfile);
			unlink($lockfile);
			return false;
		}
		return true;
	}
	return false;
}

function cron_run_cleanup() {
	global $lockfile;

	if (file_exists($lockfile)) {
		unlink($lockfile);
	}
}

function cron_process_run(Run $run, $run_lockfile) {
	global $site;
	$start_date = date('r');
	$start_time = microtime(true);
	$logfile = get_log_file("cron/cron-run-{$run->name}.log");

	if (cron_lock_exists($run_lockfile, $start_date, $logfile)) {
		return false;
	}

	file_put_contents($run_lockfile, $start_date);
	cron_log('----------', $logfile);
	cron_log("cron-run call start for {$run->name}", $logfile);

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
	cron_log($msg, $logfile);
	if ($site->alerts) {
		cron_log("\n<alerts>\n" . $site->renderAlerts() . "\n</alerts>", $logfile);
	}

	// log execution time
	$exec_time = microtime(true) - $start_time;
	$lasted = $exec_time > 60 ? ceil($exec_time / 60) . ' minutes' : ceil($exec_time) . ' seconds';
	cron_log("Cron ran for {$lasted}", $logfile);
	cron_log("cron-run call end for {$run->name}", $logfile);
	if (file_exists($run_lockfile)) {
		unlink($run_lockfile);
	}
	cron_run_cleanup();

	return true;
}

// Global required variables
$site = Site::getInstance();
$fdb = DB::getInstance();
$user = new User($fdb, null, null);
$user->cron = true;

// IF cron.php is executed with a -n option, then run cron only for particular run whose name is specified in the -n option
$opts = getopt('n:');
if (!empty($opts['n'])) {
	$name = $opts['n'];
	$run = new Run($fdb, $name);
	if (!$run->valid) {
		echo "Run not found";
		exit(1);
	}

	$lockfile = INCLUDE_ROOT . "tmp/cron-{$name}.lock";
	register_shutdown_function('cron_run_cleanup');
	cron_process_run($run, $lockfile);
	unset($site, $user, $run);
	exit(0);
}

// ELSE cron.php is called with no parameters

// Define required variables and set maximum execution time for cron script
$start_date = date('r');
$start_time = microtime(true);
$max_exec_time = (int)Config::get('cron.ttl_cron') * 60;
$intercept_if_expired = (int)Config::get('cron.intercept_if_expired');
$lockfile = INCLUDE_ROOT . 'tmp/cron.lock';

set_time_limit($max_exec_time);

// Check if lock file exists to prevent overlapping
if (cron_lock_exists($lockfile, $start_date, $logfile)) {
	exit(0);
}

// Lock cron
file_put_contents($lockfile, $start_date);
register_shutdown_function('cron_cleanup');

/** Do the Work */
cron_log("Cron started .... {$start_date}", $logfile);

// Wrap in a try catch just in case because we can't see shit
try {
	// Get all runs
	$runs = $fdb->select('name')->from('survey_runs')->where('cron_active = 1')->order('cron_fork', 'DESC')->fetchAll();

	$r = 0;
	foreach ($runs as $run_data) {
		$i = 0;
		$r++;
		$done = array('Pause' => 0, 'Email' => 0, 'SkipForward' => 0, 'SkipBackward' => 0, 'Shuffle' => 0);
		$created = date('Y-m-d H:i:s');

		$run = new Run($fdb, $run_data['name']);
		if (!$run->valid) {
			alert("This run '{$run_data['name']}' caused problems", 'alert-danger');
			continue;
		}

		// If run is locked, do not process it
		$run_lockfile = INCLUDE_ROOT . "tmp/cron-{$run->name}.lock";
		if (cron_lock_exists($run_lockfile, $start_date, get_log_file("cron/cron-run-{$run->name}.log"))) {
			continue;
		}

		// If run should be forked, run in separate process. Else process in this loop
		if ($run->cron_fork) {
			$script = dirname(__FILE__) . '/cron.php';
			$stdout = get_log_file("cron/cron-run-{$run->name}.log");
			$command = "php $script -n {$run->name} >> {$stdout} 2>&1 &";
			cron_log("Execute Command Run: '{$command}'", $logfile);
			exec($command, $output, $status);
			if ($status != 0) {
				cron_log("Command '{$command}' exited with status {$status}. Output: " . print_r($output, 1), $logfile);
			}
			continue;
		} else {
			cron_log("Execute Loop Run: '{$run->name}'", $logfile);
			cron_process_run($run, $run_lockfile);
		}

		if ($intercept_if_expired && microtime(true) - $start_time > $max_exec_time) {
			throw new Exception("Cron Intercepted! Started at: $start_date, Intercepted at: " . date('r'));
		}
	}
} catch (Exception $e) {
	error_log('Cron [Exception]: ' . $e->getMessage());
	error_log('Cron [Exception]: ' . $e->getTraceAsString());
}

$user->cron = false;

$minutes = round((microtime(true) - $start_time) / 60, 3);
$end_date = date('r');
cron_log("Cron ended .... {$end_date}. Took ~{$minutes} minutes", $logfile);
// Do cleanup just in case
cron_cleanup();

unset($site, $user, $run);
exit(0);
