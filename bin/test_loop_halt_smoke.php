#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for the run-session execution ceiling (review 2026-07,
 * item 10).
 *
 * A misconfigured run with a Skip cycle used to be spam()-ended (irreversibly
 * ejecting the participant); the F11 redesign's per-position revisit counter
 * plus a 200-execution ceiling made the loop verdict unreachable for cycles
 * spanning >40 positions — each daemon poll then burned ~200 executions
 * forever. Simplified design (user decision): one configurable ceiling
 * (run_session.max_execution_count, default 10 automated units per request);
 * exceeding it HALTS the request without ending the run session. Loops are
 * cheap and bounded per request; nothing is ended irreversibly.
 *
 * Asserts, driving the real RunSession::execute() over a 2-unit
 * SkipForward/SkipBackward cycle (constant-true conditions — evaluated by
 * shortcut_without_opencpu, no OpenCPU traffic):
 *  A. the request halts: run session survives (ended IS NULL);
 *  B. executions per request are bounded by the ceiling (not ~200);
 *  C. a second execute() also survives — halt, not spam-end, every time.
 *
 * Usage:  docker exec formr_app php bin/test_loop_halt_smoke.php
 * Creates a throwaway user + run + 2 skip units; removes them in finally.
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();
$failures = 0;
function ok($cond, string $label): void {
    global $failures;
    echo $cond ? "  \e[32mOK\e[0m  {$label}\n" : "  \e[31mFAIL\e[0m {$label}\n";
    if (!$cond) { $GLOBALS['failures']++; }
}

$uid = null; $run_id = null; $unit_ids = array(); $rs_id = null;
try {
    $db->exec("INSERT INTO survey_users (user_code, email, created) VALUES (:c, :e, NOW())",
        ['c' => bin2hex(random_bytes(24)), 'e' => 'zzloop' . getmypid() . '@example.invalid']);
    $uid = (int) $db->lastInsertId();
    $db->exec("INSERT INTO survey_runs (user_id, created, modified, name, public, cron_active)
               VALUES (:u, NOW(), NOW(), :n, 0, 0)", ['u' => $uid, 'n' => 'zzloop' . getmypid()]);
    $run_id = (int) $db->lastInsertId();

    // the cycle: pos 1 SkipForward -> 2 (always), pos 2 SkipBackward -> 1 (always)
    $mkSkip = function (string $type, int $position, int $target) use ($db, $run_id, &$unit_ids) {
        $db->exec("INSERT INTO survey_units (type, created, modified) VALUES (:t, NOW(), NOW())", ['t' => $type]);
        $unit_id = (int) $db->lastInsertId();
        $unit_ids[] = $unit_id;
        $db->exec("INSERT INTO survey_branches (id, `condition`, if_true, automatically_jump, automatically_go_on)
                   VALUES (:id, 'TRUE', :tgt, 1, 1)", ['id' => $unit_id, 'tgt' => $target]);
        $db->exec("INSERT INTO survey_run_units (run_id, unit_id, position) VALUES (:r, :u, :p)",
            ['r' => $run_id, 'u' => $unit_id, 'p' => $position]);
    };
    $mkSkip('SkipForward', 1, 2);
    $mkSkip('SkipBackward', 2, 1);

    $session = bin2hex(random_bytes(32));
    $db->exec("INSERT INTO survey_run_sessions (run_id, session, created) VALUES (:r, :s, NOW())",
        ['r' => $run_id, 's' => $session]);
    $rs_id = (int) $db->lastInsertId();

    $countUnitSessions = function () use ($db, $rs_id) {
        return (int) $db->execute("SELECT COUNT(*) FROM survey_unit_sessions WHERE run_session_id = :rs",
            ['rs' => $rs_id], true);
    };
    $sessionEnded = function () use ($db, $rs_id) {
        return $db->execute("SELECT ended FROM survey_run_sessions WHERE id = :rs", ['rs' => $rs_id], true);
    };

    echo "== A/B: a skip cycle halts the request, bounded, without ending the session ==\n";
    $run = new Run(null, $run_id);
    $runSession = new RunSession($session, $run, ['user' => new User(null, null, ['cron' => true])]);
    $runSession->execute();
    $n1 = $countUnitSessions();
    ok($sessionEnded() === null, "run session survives the loop (ended IS NULL — halted, not spam-ended)");
    $ceiling = (int) Config::get('run_session.max_execution_count', 10);
    ok($n1 > 2 && $n1 <= $ceiling + 3,
        "executions bounded by the ceiling ({$n1} unit sessions for ceiling {$ceiling}, not ~200)");

    echo "\n== C: the next request halts again (no irreversible end, ever) ==\n";
    $runSession2 = new RunSession($session, $run, ['user' => new User(null, null, ['cron' => true])]);
    $runSession2->execute();
    ok($sessionEnded() === null, "run session still alive after a second request");
    ok($countUnitSessions() <= $n1 + $ceiling + 3, "second request equally bounded");
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
        $db->exec("DELETE FROM survey_run_metrics WHERE run_id = :id", ['id' => $run_id]);
    }
    foreach ($unit_ids as $u) {
        $db->exec("DELETE FROM survey_branches WHERE id = :id", ['id' => $u]);
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
