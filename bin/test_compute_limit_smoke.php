#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for ComputeLimitCron hard-stop semantics (port of
 * 65a80b44 from feature/form_v2; review 2026-07 item 6/8).
 *
 * Asserts:
 *  A. close reaches every still-ACTIVE run: public > 0 OR cron_active = 1 —
 *     a non-public run whose daemon is still on burns compute and must be
 *     paused too (the daemon pickup never checks `public`);
 *  B. snapshot columns are audit markers: a run closed earlier keeps its
 *     original compute_closed_from / compute_closed_cron_active;
 *  C. hard stop: dropping back under the limit reopens NOTHING — the owner
 *     must republish + re-enable cron deliberately.
 *
 * Usage:  docker exec formr_app php bin/test_compute_limit_smoke.php
 * Creates a throwaway user + runs (+ one run session/unit session for the
 * over-limit compute); removes them in finally. Aborts if any real user is
 * already over their limit (the cron sweep would close their real runs).
 */
require_once dirname(__FILE__) . '/../setup.php';
require_once dirname(__FILE__) . '/../application/ComputeLimitCron.php';

$db = DB::getInstance();
$failures = 0;
function ok($cond, string $label): void {
    global $failures;
    echo $cond ? "  \e[32mOK\e[0m  {$label}\n" : "  \e[31mFAIL\e[0m {$label}\n";
    if (!$cond) { $GLOBALS['failures']++; }
}

// safety: the cron sweeps ALL users — abort if a real user would be closed
$overlimit = $db->execute("
    SELECT COUNT(*) FROM (
        SELECT u.id, u.compute_limit_monthly AS lim,
               COALESCE(SUM(CASE WHEN us.created >= DATE_FORMAT(NOW(), '%Y-%m-01')
                                 THEN us.execution_time END), 0) AS used
        FROM survey_users u
        JOIN survey_runs r ON r.user_id = u.id
        LEFT JOIN survey_run_sessions rs ON rs.run_id = r.id
        LEFT JOIN survey_unit_sessions us ON us.run_session_id = rs.id
        WHERE u.compute_limit_monthly IS NOT NULL AND u.compute_limit_monthly > 0
        GROUP BY u.id, u.compute_limit_monthly HAVING used >= lim
    ) t", array(), true);
if ((int) $overlimit > 0) {
    fwrite(STDERR, "ABORT: a real user is over their compute limit; running the cron would close their runs.\n");
    exit(1);
}

$uid = null; $run_ids = array(); $rs_id = null; $lock = APPLICATION_ROOT . 'tmp/zzcomputesmoke.lock';
$mkCron = function () use ($db, $lock) {
    return new ComputeLimitCron($db, Site::getInstance(), new User(null, null, ['cron' => true]),
        (array) Config::get('cron'), ['lockfile' => $lock]);
};
$runState = function ($rid) use ($db) {
    return $db->execute("SELECT public, cron_active, compute_closed_from, compute_closed_cron_active
                         FROM survey_runs WHERE id = :id", ['id' => $rid], false, true);
};
try {
    $db->exec("INSERT INTO survey_users (user_code, email, created) VALUES (:c, :e, NOW())",
        ['c' => bin2hex(random_bytes(24)), 'e' => 'zzcompute' . getmypid() . '@example.invalid']);
    $uid = (int) $db->lastInsertId();
    // budget of 1 second — trivially exceeded by the fixture below
    $db->exec("UPDATE survey_users SET compute_limit_monthly = 1 WHERE id = :id", ['id' => $uid]);

    // A: non-public but daemon on (burns compute; the current bug leaves it running)
    // B: enrolled-only public, daemon on (the case the old close already caught)
    // C: closed EARLIER by the limiter — markers must survive re-closes untouched
    $mk = function ($name, $public, $cron, $ccf = null, $ccc = null) use ($db, $uid, &$run_ids) {
        $db->exec("INSERT INTO survey_runs (user_id, created, modified, name, public, cron_active, compute_closed_from, compute_closed_cron_active)
                   VALUES (:u, NOW(), NOW(), :n, :p, :c, :ccf, :ccc)",
            ['u' => $uid, 'n' => $name . getmypid(), 'p' => $public, 'c' => $cron, 'ccf' => $ccf, 'ccc' => $ccc]);
        return $run_ids[] = (int) $db->lastInsertId();
    };
    $runA = $mk('zzcpA', 0, 1);
    $runB = $mk('zzcpB', 1, 1);
    $runC = $mk('zzcpC', 0, 0, 2, 1);

    // this-month compute on run A: 999s against a 1s budget
    $unit_id = (int) $db->execute("SELECT MIN(id) FROM survey_units", array(), true);
    $db->exec("INSERT INTO survey_run_sessions (run_id, session, created) VALUES (:r, :s, NOW())",
        ['r' => $runA, 's' => bin2hex(random_bytes(24))]);
    $rs_id = (int) $db->lastInsertId();
    $db->exec("INSERT INTO survey_unit_sessions (unit_id, run_session_id, created, execution_time) VALUES (:u, :rs, NOW(), 999)",
        ['u' => $unit_id, 'rs' => $rs_id]);

    echo "== A/B: over-limit close reaches every still-active run ==\n";
    $mkCron()->execute();
    $a = $runState($runA); $b = $runState($runB); $c = $runState($runC);
    ok((int) $a['cron_active'] === 0, "run A (public=0, cron on): cron paused");
    ok($a['compute_closed_from'] !== null && (int) $a['compute_closed_from'] === 0
        && (int) $a['compute_closed_cron_active'] === 1, "run A: audit markers {public:0, cron:1}");
    ok((int) $b['public'] === 0 && (int) $b['cron_active'] === 0, "run B (public=1, cron on): fully closed");
    ok((int) $b['compute_closed_from'] === 1 && (int) $b['compute_closed_cron_active'] === 1,
        "run B: audit markers {public:1, cron:1}");
    ok((int) $c['compute_closed_from'] === 2 && (int) $c['compute_closed_cron_active'] === 1,
        "run C (closed earlier): original markers preserved");

    echo "\n== C: hard stop — back under the limit reopens nothing ==\n";
    $db->exec("UPDATE survey_users SET compute_limit_monthly = 0 WHERE id = :id", ['id' => $uid]); // unlimited
    $mkCron()->execute();
    $a = $runState($runA); $b = $runState($runB); $c = $runState($runC);
    ok((int) $a['public'] === 0 && (int) $a['cron_active'] === 0, "run A stays closed");
    ok((int) $b['public'] === 0 && (int) $b['cron_active'] === 0, "run B stays closed (no auto-reopen)");
    ok((int) $c['public'] === 0 && (int) $c['cron_active'] === 0, "run C stays closed (no auto-reopen)");
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    $failures++;
} finally {
    if ($rs_id) {
        $db->exec("DELETE FROM survey_unit_sessions WHERE run_session_id = :id", ['id' => $rs_id]);
        $db->exec("DELETE FROM survey_run_sessions WHERE id = :id", ['id' => $rs_id]);
    }
    foreach ($run_ids as $rid) {
        $db->exec("DELETE FROM survey_run_metrics WHERE run_id = :id", ['id' => $rid]);
        $db->exec("DELETE FROM survey_runs WHERE id = :id", ['id' => $rid]);
    }
    if ($uid) {
        $db->exec("DELETE FROM survey_users WHERE id = :id", ['id' => $uid]);
    }
    @unlink($lock);
}

echo "\n" . ($failures === 0 ? "\e[32mALL PASSED\e[0m\n" : "\e[31m{$failures} FAILURE(S)\e[0m\n");
exit($failures === 0 ? 0 : 1);
