#!/usr/bin/php
<?php
// Issue #608: enforce per-user monthly compute limits — close runs that put a
// study admin over their budget, reopen them when usage drops back under it.
// Schedule alongside the other crons (see config/formr_crontab); hourly is a
// good cadence (limit overshoot is bounded by the interval).
require_once dirname(__FILE__) . '/../setup.php';
require_once dirname(__FILE__) . '/../application/ComputeLimitCron.php';

$site = Site::getInstance();
$fdb = DB::getInstance();
$user = new User(null, null, ['cron' => true]);
$cronConfig = Config::get('cron');

$params['lockfile'] = APPLICATION_ROOT . 'computeLimitsCron.lock';

$cron = new ComputeLimitCron($fdb, $site, $user, $cronConfig, $params);
$cron->execute();

// Keep the display rollup fresh even on hosts that don't run the dedicated
// 10-minute refresh cron (audit §6.2). Enforcement above reads live, so this
// only affects the compute-usage dashboards / admin lists.
RunMetrics::refresh();

unset($site, $fdb, $user, $params, $cronConfig);
exit(0);
