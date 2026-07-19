#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for Notification::canBeSent testing-session handling
 * (review 2026-07, item 11).
 *
 * The issue-#608 guard suppressed study-admin error emails for EVERY
 * testing-flagged session — including cron/daemon-driven ones, where email is
 * the ONLY error channel (on-screen alerts need a browser). A researcher
 * piloting their run as a testing session before launch heard nothing when
 * their relative_to R code broke in the daemon at night. The suppression must
 * apply only to interactive WEB requests (the tester already sees the
 * on-screen alert); in console/cron context the email must go out.
 *
 * This script runs under CLI, i.e. exactly the console context the bug
 * silenced. (The web-context suppression arm can't be exercised from CLI —
 * it is the pre-existing, intended behaviour and unchanged by the fix.)
 *
 * Asserts (via ReflectionMethod on the real Notification::canBeSent):
 *  A. testing session + console context -> SENDABLE (red pre-fix: false);
 *  B. non-testing session + console     -> sendable (unchanged);
 *  C. the throttle still applies on repeat within the window.
 *
 * Usage:  docker exec formr_app php bin/test_notify_testing_smoke.php
 * Creates a throwaway user + run + sessions; removes them in finally.
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();
$failures = 0;
function ok($cond, string $label): void {
    global $failures;
    echo $cond ? "  \e[32mOK\e[0m  {$label}\n" : "  \e[31mFAIL\e[0m {$label}\n";
    if (!$cond) { $GLOBALS['failures']++; }
}

$uid = null; $run_id = null; $rs_ids = array();
try {
    $db->exec("INSERT INTO survey_users (user_code, email, created) VALUES (:c, :e, NOW())",
        ['c' => bin2hex(random_bytes(24)), 'e' => 'zznotify' . getmypid() . '@example.invalid']);
    $uid = (int) $db->lastInsertId();
    $db->exec("INSERT INTO survey_runs (user_id, created, modified, name, public, cron_active)
               VALUES (:u, NOW(), NOW(), :n, 0, 0)", ['u' => $uid, 'n' => 'zznotify' . getmypid()]);
    $run_id = (int) $db->lastInsertId();
    $run = new Run(null, $run_id);

    $mkSession = function (int $testing) use ($db, $run, $run_id, &$rs_ids) {
        $code = bin2hex(random_bytes(32));
        $db->exec("INSERT INTO survey_run_sessions (run_id, session, created, testing) VALUES (:r, :s, NOW(), :t)",
            ['r' => $run_id, 's' => $code, 't' => $testing]);
        $rs_ids[] = (int) $db->lastInsertId();
        $rs = new RunSession($code, $run, ['user' => new User(null, null, ['cron' => true])]);
        return new UnitSession($rs);
    };

    $canBeSent = new ReflectionMethod('Notification', 'canBeSent');
    $canBeSent->setAccessible(true);
    $notification = Notification::getInstance();

    echo "== A: testing session, console context (the cron pilot case) ==\n";
    $usTesting = $mkSession(1);
    ok((bool) $usTesting->runSession->isTesting(), "fixture session is testing-flagged");
    ok($canBeSent->invoke($notification, $usTesting, 'error') === true,
        "canBeSent: testing + console -> true (email is the only channel for cron errors)");

    echo "\n== B: non-testing session, console context ==\n";
    $usReal = $mkSession(0);
    ok($canBeSent->invoke($notification, $usReal, 'error') === true,
        "canBeSent: real participant session -> true (unchanged)");

    echo "\n== C: throttle still bounds volume ==\n";
    // record a just-sent notification for this run+owner+type, then re-ask
    // (session_id FKs to survey_unit_sessions — create a real row for it)
    $unit_id = (int) $db->execute("SELECT MIN(id) FROM survey_units", array(), true);
    $db->exec("INSERT INTO survey_unit_sessions (unit_id, run_session_id, created) VALUES (:u, :rs, NOW())",
        ['u' => $unit_id, 'rs' => end($rs_ids)]);
    $us_id = (int) $db->lastInsertId();
    $db->exec("INSERT INTO survey_notifications (run_id, session_id, recipient_id, message, type, created) VALUES (:r, :sid, :u, 'smoke', 'error', NOW())",
        ['r' => $run_id, 'sid' => $us_id, 'u' => $uid]);
    ok($canBeSent->invoke($notification, $usTesting, 'error') === false,
        "canBeSent: false within the throttle window (throttling intact)");
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    $failures++;
} finally {
    if ($run_id) {
        $db->exec("DELETE FROM survey_notifications WHERE run_id = :r", ['r' => $run_id]);
    }
    foreach ($rs_ids as $rs) {
        $db->exec("DELETE FROM survey_unit_sessions WHERE run_session_id = :id", ['id' => $rs]);
        $db->exec("DELETE FROM survey_run_sessions WHERE id = :id", ['id' => $rs]);
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
