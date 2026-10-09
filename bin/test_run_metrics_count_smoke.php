#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for RunMetrics::count() zero-vs-missing semantics
 * (review 2026-07: post-069 pagination blackout).
 *
 * Patch 068 seeds a survey_run_metrics row for EVERY existing run; patch 069
 * then adds n_unit_sessions/n_push_logs/n_email_logs DEFAULT 0 with no
 * backfill. A rollup value of 0 is therefore indistinguishable from
 * "never reconciled" — so count() must treat 0 like a missing row and return
 * null, letting the callers' live-COUNT fallback fire (correct immediately at
 * pre-rollup cost; cheap when the run is genuinely empty). Otherwise every
 * admin push/email/user-detail table paginates from a fake zero until the
 * first nightly reconcile — forever, if metrics_reconcile_enabled=false.
 *
 * Usage:  docker exec formr_app php bin/test_run_metrics_count_smoke.php
 * Perturbs one run's rollup counter and restores it in finally.
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();
$failures = 0;
function ok($cond, string $label): void {
    global $failures;
    echo $cond ? "  \e[32mOK\e[0m  {$label}\n" : "  \e[31mFAIL\e[0m {$label}\n";
    if (!$cond) { $GLOBALS['failures']++; }
}

// a run with real push logs (the live ground truth for the fallback)
$row = $db->execute("
    SELECT run_id, COUNT(*) AS live_count FROM push_logs
    GROUP BY run_id ORDER BY live_count DESC LIMIT 1", array(), false, true);
if (!$row) { fwrite(STDERR, "no run with push logs on this instance\n"); exit(1); }
$run_id = (int) $row['run_id'];
$live = (int) $row['live_count'];
echo "run {$run_id}: live push-log count = {$live}\n";

$saved = $db->execute("SELECT n_push_logs FROM survey_run_metrics WHERE run_id = :rid", ['rid' => $run_id], true);
try {
    // simulate the fresh post-069 state: rollup row EXISTS but the counter is 0
    $db->exec("UPDATE survey_run_metrics SET n_push_logs = 0 WHERE run_id = :rid", ['rid' => $run_id]);

    echo "\n== A: count() treats a zero counter as 'no data yet' ==\n";
    ok(RunMetrics::count($run_id, 'n_push_logs') === null,
        "count(n_push_logs) returns null on a zeroed (never-reconciled) counter");

    echo "\n== B: the admin push-log table paginates from the live count ==\n";
    $run = new Run(null, $run_id);
    $helper = new RunHelper($run, $db, new Request());
    $table = $helper->getPushMessageLogTable(array('run_id' => $run_id));
    ok($table['pagination']->maximum === $live,
        "getPushMessageLogTable pagination = live count ({$table['pagination']->maximum} vs {$live})");

    echo "\n== C: a reconciled (non-zero) counter is served from the rollup ==\n";
    $db->exec("UPDATE survey_run_metrics SET n_push_logs = :v WHERE run_id = :rid", ['v' => $live, 'rid' => $run_id]);
    ok(RunMetrics::count($run_id, 'n_push_logs') === $live, "count() returns the rollup value when non-zero");
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    $failures++;
} finally {
    if ($saved !== false && $saved !== null) {
        $db->exec("UPDATE survey_run_metrics SET n_push_logs = :v WHERE run_id = :rid", ['v' => $saved, 'rid' => $run_id]);
    }
}

echo "\n" . ($failures === 0 ? "\e[32mALL PASSED\e[0m\n" : "\e[31m{$failures} FAILURE(S)\e[0m\n");
exit($failures === 0 ? 0 : 1);
