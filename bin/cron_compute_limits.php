#!/usr/bin/php
<?php
// Issue #608: enforce per-user monthly compute limits — close (public = 0,
// cron_active = 0) the runs of any study admin over their budget. Compute-closed
// runs are not reopened automatically; the owner republishes them by hand.
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

// The metrics rollup is reconciled by its own nightly cron
// (cron_reconcile_metrics.php); enforcement above reads live, so it does not
// depend on the rollup at all.

unset($site, $fdb, $user, $params, $cronConfig);
exit(0);
