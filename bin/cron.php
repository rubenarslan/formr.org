#!/usr/bin/php
<?php
require_once dirname(__FILE__) . '/../setup.php';

// Check if maintenance is going on
if (Config::get('in_maintenance')) {
    formr_error(404, 'Not Found', 'This website is currently undergoing maintenance. Please try again later.', 'Maintenace Mode', false);
}

/*
// If we are processing unit sessions via db queue then cron should not run
if (Config::get('unit_session.use_queue')) {
    echo "\nProcessing Sessions in DB Queue\n";
    exit(0);
}
 */

// Global required variables
$site = Site::getInstance();
$fdb = DB::getInstance();
$user = new User($fdb, null, null);
$user->cron = true;
$cronConfig = Config::get('cron');

// IF cron.php is executed with a -n option, then run cron only for particular run whose name is specified in the -n option
$opts = getopt('n:');
if (!empty($opts['n'])) {
    $name = $opts['n'];
    $run = new Run($fdb, $name);
    if (!$run->valid) {
        exit('Run Not Found');
    }
    $params['lockfile'] = APPLICATION_ROOT . "tmp/cron-{$run->name}.lock";
    $params['logfile'] = get_log_file("cron/cron-run-{$run->name}.log");
    $params['process_run'] = $run;
} else {
    $params['lockfile'] = APPLICATION_ROOT . 'tmp/cron.lock';
    $params['logfile'] = $logfile = get_log_file('cron.log');
    $params['process_run'] = false;
}

$cron = new Cron($fdb, $site, $user, $cronConfig, $params);
$cron->execute();

unset($site, $fdb, $user, $params, $cronConfig);
exit(0);