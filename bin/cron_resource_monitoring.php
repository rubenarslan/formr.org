#!/usr/bin/php
<?php
require_once dirname(__FILE__) . '/../setup.php';

// Global required variables
$site = Site::getInstance();
$fdb = DB::getInstance();
$user = new User(null, null, ['cron' => true]);
$cronConfig = Config::get('cron');

$params['lockfile'] = APPLICATION_ROOT . 'tmp/cron_resource_monitoring.lock';
$params['logfile'] = get_log_file('cron/cron_resource_monitoring.log');

$cron = new ResourceMonitoringCron($fdb, $site, $user, $cronConfig, $params);
$cron->execute();

unset($site, $fdb, $user, $params, $cronConfig);
exit(0);
