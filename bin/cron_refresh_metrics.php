#!/usr/bin/php
<?php
// Slow-query audit 2026-07 §6.2: recompute the per-run rollup that backs the
// compute-usage dashboards and admin run/user lists (SQ-13/16/17/18/21), so
// those views read O(runs) rollup rows instead of re-scanning the full
// survey_run_sessions / survey_unit_sessions history each time. Backs display
// only — ComputeLimitCron enforcement reads live — so a modest refresh interval
// (half-hourly at :07/:37; see config/formr_crontab) is plenty.
require_once dirname(__FILE__) . '/../setup.php';

$affected = RunMetrics::refresh();
formr_log("cron_refresh_metrics: refreshed {$affected} run metric row(s)", 'CRON_INFO');
exit(0);
