#!/usr/bin/php
<?php
require_once dirname(__FILE__) . '/../setup.php';
require_once dirname(__FILE__) . '/../application/RunExpiresOnCron.php';

// Global required variables
$site = Site::getInstance();
$fdb = DB::getInstance();
$user = new User($fdb, null, null);
$user->cron = true;
$cronConfig = Config::get('cron');

$params['logfile'] = get_log_file('expiredRunsCron.log');
$params['lockfile'] = APPLICATION_ROOT . 'expiredRunsCron.lock';

$cron = new RunExpiresOnCron($fdb, $site, $user, $cronConfig, $params);
$cron->execute();

unset($site, $fdb, $user, $params, $cronConfig);
exit(0);