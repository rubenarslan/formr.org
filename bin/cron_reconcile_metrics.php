#!/usr/bin/php
<?php
// Nightly ground-truth reconciliation of the metrics rollups (write-time
// metrics accounting — documentation/agent_doc/write_time_metrics_plan.md).
// The write hooks keep study response counts fresh between runs; this pass is
// the only full scan of history — it rebuilds the run/compute rollup and
// corrects any drift in the study rollup from the un-hooked paths (testing
// toggle, deletes). Config-gated: RunMetrics::reconcile() no-ops (returns -1)
// when metrics_reconcile_enabled=false, so an instance that doesn't watch
// compute can carry the cron cheaply. Schedule off-peak (see config/formr_crontab).
require_once dirname(__FILE__) . '/../setup.php';

$affected = RunMetrics::reconcile();
if ($affected < 0) {
    formr_log('cron_reconcile_metrics: skipped (metrics_reconcile_enabled=false)', 'CRON_INFO');
} else {
    formr_log("cron_reconcile_metrics: reconciled {$affected} run metric row(s) + study metrics", 'CRON_INFO');
}
exit(0);
