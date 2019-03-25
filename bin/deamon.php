#!/usr/bin/php
<?php
require_once dirname(__FILE__) . '/../setup.php';

function _log($message) {
    $message = date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
    return error_log($message, 3, get_log_file('deamon.log'));
}

if (!function_exists('gearman_version')) {
    echo 'Gearman not installed' . PHP_EOL;
    exit(90);
}

// Global required variables
try {
    $site = Site::getInstance();
    $fdb = DB::getInstance();
    $user = new User($fdb, null, null);
    $user->cron = true;
    $opts = getopt('w:a:t:c');
    $worker = isset($opts['w']) ? $opts['w'] : null;
    $amount = isset($opts['a']) ? $opts['a'] : 5;
    $timeout = isset($opts['t']) ? $opts['t'] : 40;
    $cronize = isset($opts['c']);
} catch (Exception $e) {
    _log("Deamon Start-up Error: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString());
    exit(1);
}

if ($worker) {
    // Run the Workers
    try {
        _log("Starting {$worker} workers...");
        $workerClass = $worker . 'WorkerHelper';
        $worker = new $workerClass();
        $worker->doJobs($amount, $timeout);
    } catch (Exception $e) {
        _log("Worker Error [{$worker}]: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString());
        exit(1);
    }
} else {
    // Run the Deamon
    try {
        _log('Starting deamon...');
        $deamon = new Deamon($fdb);
        $ran = empty($cronize) ? $deamon->run() : $deamon->cronize();
    } catch (Exception $e) {
        _log("Deamon Error: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString());
        exit(1);
    }
}
