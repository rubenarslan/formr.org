#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for Run::sendReminder failure handling (review 2026-07,
 * item 9).
 *
 * RunSession::createUnitSession deliberately installs NULL as the current
 * unit session when UnitSession::create() fails (the audit hardening), and
 * Run::getReminderSession returns that field directly — so a create failure
 * (deleted/stale reminder id posted by an old admin page, DB error,
 * non-adoptable UNIQUE collision) hands sendReminder a null. It must fail
 * SOFT (return false; every caller already alerts on false and the bulk loop
 * moves on to the next session), not fatal on ->execute() — the fatal aborted
 * whole bulk-reminder requests.
 *
 * Usage:  docker exec formr_app php bin/test_reminder_smoke.php
 * Creates a throwaway user + run + run session; removes them in finally.
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();
$failures = 0;
function ok($cond, string $label): void {
    global $failures;
    echo $cond ? "  \e[32mOK\e[0m  {$label}\n" : "  \e[31mFAIL\e[0m {$label}\n";
    if (!$cond) { $GLOBALS['failures']++; }
}

$uid = null; $run_id = null; $rs_id = null;
try {
    $db->exec("INSERT INTO survey_users (user_code, email, created) VALUES (:c, :e, NOW())",
        ['c' => bin2hex(random_bytes(24)), 'e' => 'zzremind' . getmypid() . '@example.invalid']);
    $uid = (int) $db->lastInsertId();
    $db->exec("INSERT INTO survey_runs (user_id, created, modified, name, public, cron_active)
               VALUES (:u, NOW(), NOW(), :n, 0, 0)", ['u' => $uid, 'n' => 'zzremind' . getmypid()]);
    $run_id = (int) $db->lastInsertId();
    $session = bin2hex(random_bytes(32));
    $db->exec("INSERT INTO survey_run_sessions (run_id, session, created) VALUES (:r, :s, NOW())",
        ['r' => $run_id, 's' => $session]);
    $rs_id = (int) $db->lastInsertId();

    echo "== sendReminder with a nonexistent reminder id fails soft ==\n";
    $run = new Run(null, $run_id);
    ok($run->valid, "test run {$run_id} loaded");
    $threw = null;
    $result = 'unset';
    try {
        // 999999999 does not exist -> unit-session INSERT fails ->
        // createUnitSession installs null -> getReminderSession returns null
        $result = $run->sendReminder(999999999, $session, $rs_id);
    } catch (Throwable $e) {
        $threw = $e;
    }
    ok($threw === null, "no fatal/throw (" . ($threw ? get_class($threw) . ': ' . $threw->getMessage() : 'clean') . ")");
    ok($result === false, "returns false so callers alert and bulk loops continue");
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    $failures++;
} finally {
    if ($rs_id) {
        $db->exec("DELETE FROM survey_unit_sessions WHERE run_session_id = :id", ['id' => $rs_id]);
        $db->exec("DELETE FROM survey_run_sessions WHERE id = :id", ['id' => $rs_id]);
    }
    if ($run_id) {
        $db->exec("DELETE FROM survey_run_metrics WHERE run_id = :id", ['id' => $run_id]);
        $db->exec("DELETE FROM survey_runs WHERE id = :id", ['id' => $run_id]);
    }
    if ($uid) {
        $db->exec("DELETE FROM survey_users WHERE id = :id", ['id' => $uid]);
    }
}

echo "\n" . ($failures === 0 ? "\e[32mALL PASSED\e[0m\n" : "\e[31m{$failures} FAILURE(S)\e[0m\n");
exit($failures === 0 ? 0 : 1);
