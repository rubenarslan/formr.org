#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for write-time metrics accounting (v1.7.0).
 * See documentation/agent_doc/write_time_metrics_plan.md.
 *
 * Asserts the invariants that make write-time counters safe:
 *  A. reconcile() is idempotent (deterministic ground truth).
 *  B. reconciled study counts == a live getResultCount-shaped scan.
 *  C. the write hooks (onSurveyStart/onSurveyComplete) move counters by
 *     exactly the delta a reconcile would, and reconcile heals back to
 *     ground truth (reconcile-equality).
 *
 * Usage:  docker exec formr_app php bin/test_write_time_metrics_smoke.php
 * Exits 0 on success. Read-mostly: section C perturbs one real study's
 * counters then reconciles them back, leaving no net change.
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();
$failures = 0;
function ok($cond, string $label): void {
    global $failures;
    echo $cond ? "  \e[32mOK\e[0m  {$label}\n" : "  \e[31mFAIL\e[0m {$label}\n";
    if (!$cond) { $GLOBALS['failures']++; }
}
function eqf($a, $b, string $label): void { ok($a === $b, $label . " (got " . var_export($a, true) . ", want " . var_export($b, true) . ")"); }
function metricsHash(DB $db): string {
    $rows = $db->execute("SELECT * FROM survey_study_metrics ORDER BY study_id");
    $run = $db->execute("SELECT run_id, n_run_sessions, n_unit_sessions, n_push_logs, n_email_logs,
        n_exec_sessions, total_execution_time, month_execution_time, max_execution_time FROM survey_run_metrics ORDER BY run_id");
    // updated_at drifts each write; exclude it from the fingerprint
    foreach ($rows as &$r) { unset($r['updated_at']); }
    return md5(json_encode([$rows, $run]));
}

try {
    // ── A: reconcile idempotence ─────────────────────────────────────────
    echo "\n== A: reconcile idempotence ==\n";
    RunMetrics::reconcile();
    $h1 = metricsHash($db);
    RunMetrics::reconcile();
    $h2 = metricsHash($db);
    eqf($h1, $h2, 'two reconciles produce identical rollups');

    // ── B: reconcile == live, for a study with a results table ───────────
    echo "\n== B: reconcile == live scan ==\n";
    $study = $db->execute("SELECT sm.study_id, s.results_table FROM survey_study_metrics sm
        JOIN survey_studies s ON s.id = sm.study_id
        WHERE sm.n_durations > 0 ORDER BY sm.n_durations DESC LIMIT 1", [], false, true);
    if (!$study) { fwrite(STDERR, "No study with completions to check.\n"); exit(2); }
    $sid = (int) $study['study_id'];
    $rt = str_replace('`', '', $study['results_table']);
    $rollup = $db->execute("SELECT begun, finished, testers, real_users, n_durations,
        ROUND(sum_log_duration, 4) sld FROM survey_study_metrics WHERE study_id = :s", ['s' => $sid], false, true);
    $live = $db->execute("
        SELECT SUM(rs.testing IS NOT NULL AND rs.testing=0 AND rt.ended IS NULL) begun,
               SUM(rs.testing IS NOT NULL AND rs.testing=0 AND rt.ended IS NOT NULL) finished,
               SUM(rs.testing IS NULL OR rs.testing=1) testers,
               SUM(rs.testing IS NOT NULL AND rs.testing=0) real_users,
               SUM(rt.ended IS NOT NULL) n_durations,
               ROUND(SUM(CASE WHEN rt.ended IS NOT NULL
                    THEN LN(GREATEST(TIMESTAMPDIFF(SECOND, rt.created, rt.ended), 1)) ELSE 0 END), 4) sld
        FROM `{$rt}` rt LEFT JOIN survey_unit_sessions us ON us.id = rt.session_id
        LEFT JOIN survey_run_sessions rs ON us.run_session_id = rs.id", [], false, true);
    foreach (['begun', 'finished', 'testers', 'real_users', 'n_durations'] as $c) {
        eqf((int) $rollup[$c], (int) $live[$c], "study {$sid}.{$c} rollup==live");
    }
    ok(abs((float) $rollup['sld'] - (float) $live['sld']) < 0.01, "study {$sid}.sum_log_duration rollup==live");
    $gm = StudyMetrics::geometricMeanSeconds($sid);
    ok($gm !== null && $gm > 0, "geometric mean computes (" . round($gm, 1) . "s)");
} catch (Throwable $e) {
    fwrite(STDERR, "SETUP/A/B ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

try {
    // ── C: hook deltas + reconcile-equality (perturb a real study, heal back) ─
    echo "\n== C: hook deltas + reconcile-equality ==\n";
    $base = StudyMetrics::counts($sid);
    // a real start then a real completion (90s): net begun +0, finished +1,
    // real_users +1, n_durations +1
    StudyMetrics::onSurveyStart($sid, 0);
    $afterStart = StudyMetrics::counts($sid);
    eqf($afterStart['begun'] - $base['begun'], 1, 'onSurveyStart(real): begun +1');
    eqf($afterStart['real_users'] - $base['real_users'], 1, 'onSurveyStart(real): real_users +1');
    StudyMetrics::onSurveyComplete($sid, 0, 90);
    $afterComplete = StudyMetrics::counts($sid);
    eqf($afterComplete['begun'] - $base['begun'], 0, 'onSurveyComplete(real): begun back to base');
    eqf($afterComplete['finished'] - $base['finished'], 1, 'onSurveyComplete(real): finished +1');
    // a tester start bumps only testers
    StudyMetrics::onSurveyStart($sid, 1);
    eqf(StudyMetrics::counts($sid)['testers'] - $base['testers'], 1, 'onSurveyStart(tester): testers +1');
    // reconcile heals every perturbation back to ground truth
    RunMetrics::reconcile();
    $healed = StudyMetrics::counts($sid);
    eqf($healed, $base, 'reconcile restores ground truth (reconcile-equality)');
} catch (Throwable $e) {
    fwrite(STDERR, "C ERROR: " . $e->getMessage() . "\n");
    $failures++;
}

echo "\n" . ($failures === 0 ? "\e[32mALL PASSED\e[0m\n" : "\e[31m{$failures} FAILURE(S)\e[0m\n");
exit($failures === 0 ? 0 : 1);
