#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for user-detail pagination vs the rollup (review
 * 2026-07, item 20).
 *
 * getUserDetailTable's items query inner-joins survey_run_units on
 * (unit_id, run_id), so it EXCLUDES sessions whose unit isn't in the run
 * structure (reminder/special units, removed units) and FANS OUT a unit
 * placed at multiple positions. The rollup n_unit_sessions counted every
 * unit session of the run with no such join, so the page count disagreed
 * with the rows shown — phantom empty trailing pages (over-count) or an
 * unreachable partial page (under-count). n_unit_sessions is now computed
 * with the same run_units join, so it equals the displayed row count.
 *
 * Asserts, on a run with a SPECIAL-unit session (excluded from the table):
 *  A. reconciled n_unit_sessions == the live filtered count == displayed rows
 *     (red pre-fix: rollup over-counts by the special-unit session).
 *
 * Usage:  docker exec formr_app php bin/test_user_detail_pagination_smoke.php
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();
$failures = 0;
function ok($cond, string $label): void {
    global $failures;
    echo $cond ? "  \e[32mOK\e[0m  {$label}\n" : "  \e[31mFAIL\e[0m {$label}\n";
    if (!$cond) { $GLOBALS['failures']++; }
}

$uid = null; $run_id = null; $unit_ids = []; $rs_id = null;
try {
    $db->exec("INSERT INTO survey_users (user_code, email, created) VALUES (:c, :e, NOW())",
        ['c' => bin2hex(random_bytes(24)), 'e' => 'zzpag' . getmypid() . '@example.invalid']);
    $uid = (int) $db->lastInsertId();
    $db->exec("INSERT INTO survey_runs (user_id, created, modified, name, public, cron_active)
               VALUES (:u, NOW(), NOW(), :n, 0, 0)", ['u' => $uid, 'n' => 'zzpag' . getmypid()]);
    $run_id = (int) $db->lastInsertId();

    // a Pause placed IN the run structure
    $db->exec("INSERT INTO survey_units (type, created, modified) VALUES ('Pause', NOW(), NOW())");
    $unitIn = $unit_ids[] = (int) $db->lastInsertId();
    $db->exec("INSERT INTO survey_pauses (id) VALUES (:id)", ['id' => $unitIn]);
    $db->exec("INSERT INTO survey_run_units (run_id, unit_id, position) VALUES (:r, :u, 1)",
        ['r' => $run_id, 'u' => $unitIn]);

    // a reminder Email registered as a SPECIAL unit (NOT in survey_run_units)
    $db->exec("INSERT INTO survey_units (type, created, modified) VALUES ('Email', NOW(), NOW())");
    $unitSpecial = $unit_ids[] = (int) $db->lastInsertId();
    $db->exec("INSERT INTO survey_emails (id) VALUES (:id)", ['id' => $unitSpecial]);
    $db->exec("INSERT INTO survey_run_special_units (id, run_id, type) VALUES (:id, :r, 'reminder')",
        ['id' => $unitSpecial, 'r' => $run_id]);

    $db->exec("INSERT INTO survey_run_sessions (run_id, session, created, position) VALUES (:r, :s, NOW(), 1)",
        ['r' => $run_id, 's' => bin2hex(random_bytes(32))]);
    $rs_id = (int) $db->lastInsertId();
    // two sessions of the structural unit + one of the special (reminder) unit
    foreach ([$unitIn, $unitIn, $unitSpecial] as $u) {
        $db->exec("INSERT INTO survey_unit_sessions (unit_id, run_session_id, created) VALUES (:u, :rs, NOW())",
            ['u' => $u, 'rs' => $rs_id]);
    }

    RunMetrics::reconcile();

    echo "== A: rollup count matches the displayed rows ==\n";
    $rollup = RunMetrics::count($run_id, 'n_unit_sessions');
    $live = (int) $db->execute(
        "SELECT COUNT(`survey_unit_sessions`.id) FROM `survey_unit_sessions`
         LEFT JOIN `survey_run_sessions` ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
         LEFT JOIN `survey_run_units` ON `survey_unit_sessions`.unit_id = `survey_run_units`.unit_id AND `survey_run_units`.run_id = `survey_run_sessions`.run_id
         WHERE `survey_run_sessions`.run_id = :r AND `survey_run_units`.run_id = :r2",
        ['r' => $run_id, 'r2' => $run_id], true);

    $helper = new RunHelper(new Run(null, $run_id), $db, new Request());
    $table = $helper->getUserDetailTable(['run_id' => $run_id]);
    $rows = count($table['data']);

    echo "  rollup={$rollup} live={$live} displayed_rows={$rows} pagination_max={$table['pagination']->maximum}\n";
    ok($rollup === $live, "rollup n_unit_sessions ({$rollup}) == live filtered count ({$live})");
    ok((int) $table['pagination']->maximum === $rows, "pagination maximum matches the displayed row count ({$rows})");
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    $failures++;
} finally {
    if ($rs_id) {
        $db->exec("DELETE FROM survey_unit_sessions WHERE run_session_id = :id", ['id' => $rs_id]);
        $db->exec("DELETE FROM survey_run_sessions WHERE id = :id", ['id' => $rs_id]);
    }
    if ($run_id) {
        $db->exec("DELETE FROM survey_run_units WHERE run_id = :id", ['id' => $run_id]);
        $db->exec("DELETE FROM survey_run_special_units WHERE run_id = :id", ['id' => $run_id]);
        $db->exec("DELETE FROM survey_run_metrics WHERE run_id = :id", ['id' => $run_id]);
    }
    foreach ($unit_ids as $u) {
        $db->exec("DELETE FROM survey_pauses WHERE id = :id", ['id' => $u]);
        $db->exec("DELETE FROM survey_emails WHERE id = :id", ['id' => $u]);
        $db->exec("DELETE FROM survey_units WHERE id = :id", ['id' => $u]);
    }
    if ($run_id) {
        $db->exec("DELETE FROM survey_runs WHERE id = :id", ['id' => $run_id]);
    }
    if ($uid) {
        $db->exec("DELETE FROM survey_users WHERE id = :id", ['id' => $uid]);
    }
}

echo "\n" . ($failures === 0 ? "\e[32mALL PASSED\e[0m\n" : "\e[31m{$failures} FAILURE(S)\e[0m\n");
exit($failures === 0 ? 0 : 1);
