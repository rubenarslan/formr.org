#!/usr/bin/php
<?php
require_once dirname(__FILE__) . '/../setup.php';
require_once dirname(__FILE__) . '/../application/RunExpiresOnCron.php';

// Global required variables
$site = Site::getInstance();
$fdb = DB::getInstance();
$user = new User(null, null, ['cron' => true]);
$cronConfig = Config::get('cron');

$params['lockfile'] = APPLICATION_ROOT . 'expiredRunsCron.lock';

$cron = new RunExpiresOnCron($fdb, $site, $user, $cronConfig, $params);
$cron->execute();

unset($site, $fdb, $user, $params, $cronConfig);
exit(0);