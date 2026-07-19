#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for the run-engine ops scripts (review 2026-07, item 18).
 *
 *  A. backfill_run_unit_id_active: a live NULL-run_unit_id row whose unit is
 *     placed at MULTIPLE positions must be skipped (stamping it with the
 *     current position's placement would manufacture cross-placement
 *     adoption); a single-placement row stays backfillable.
 *  B. sweep_stalled_unit_sessions: a live queued=0 row that the run session's
 *     current_unit_session_id POINTER designates must not be terminal-stamped
 *     when the position lookup disagrees (legacy position drift) — stamping
 *     it makes recovery advance from the drifted position, skipping gates.
 *  C. heal_duplicate_pause_sessions --apply: takes the run-session lock (an
 *     engine-held lock defers the cluster) and still heals once released.
 *
 * Usage:  docker exec formr_app php bin/test_ops_scripts_smoke.php
 * Creates throwaway fixtures; removes them in finally.
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();
$failures = 0;
function ok($cond, string $label): void {
    global $failures;
    echo $cond ? "  \e[32mOK\e[0m  {$label}\n" : "  \e[31mFAIL\e[0m {$label}\n";
    if (!$cond) { $GLOBALS['failures']++; }
}
function run_script(string $cmd): string {
    exec($cmd . ' 2>&1', $out, $rc);
    return implode("\n", $out) . "\n[exit={$rc}]";
}

$uid = null; $run_ids = []; $unit_ids = []; $rs_ids = [];
$mkRun = function ($tag, $cron = 0) use (&$run_ids, $db, &$uid) {
    $db->exec("INSERT INTO survey_runs (user_id, created, modified, name, public, cron_active)
               VALUES (:u, NOW(), NOW(), :n, 0, :c)", ['u' => $uid, 'n' => 'zz' . $tag . getmypid(), 'c' => $cron]);
    return $run_ids[] = (int) $db->lastInsertId();
};
$mkUnit = function ($type, $table) use (&$unit_ids, $db) {
    $db->exec("INSERT INTO survey_units (type, created, modified) VALUES (:t, NOW(), NOW())", ['t' => $type]);
    $id = $unit_ids[] = (int) $db->lastInsertId();
    $db->exec("INSERT INTO {$table} (id) VALUES (:id)", ['id' => $id]);
    return $id;
};
$mkPlacement = function ($run, $unit, $pos) use ($db) {
    $db->exec("INSERT INTO survey_run_units (run_id, unit_id, position) VALUES (:r, :u, :p)",
        ['r' => $run, 'u' => $unit, 'p' => $pos]);
    return (int) $db->lastInsertId();
};
$mkRs = function ($run, $pos) use (&$rs_ids, $db) {
    $db->exec("INSERT INTO survey_run_sessions (run_id, session, created, position) VALUES (:r, :s, NOW(), :p)",
        ['r' => $run, 's' => bin2hex(random_bytes(32)), 'p' => $pos]);
    return $rs_ids[] = (int) $db->lastInsertId();
};
$mkUs = function ($unit, $rs, array $extra = []) use ($db) {
    $cols = ['unit_id' => $unit, 'run_session_id' => $rs] + $extra;
    $names = implode(',', array_map(fn($c) => "`$c`", array_keys($cols)));
    $ph = implode(',', array_map(fn($c) => ":$c", array_keys($cols)));
    $db->exec("INSERT INTO survey_unit_sessions ({$names}, `created`) VALUES ({$ph}, NOW() - INTERVAL 40 MINUTE)", $cols);
    return (int) $db->lastInsertId();
};

try {
    $db->exec("INSERT INTO survey_users (user_code, email, created) VALUES (:c, :e, NOW())",
        ['c' => bin2hex(random_bytes(24)), 'e' => 'zzops' . getmypid() . '@example.invalid']);
    $uid = (int) $db->lastInsertId();

    echo "== A: backfill skips multi-placement units, keeps single-placement ==\n";
    $runA = $mkRun('opsA');
    $unitMulti = $mkUnit('Email', 'survey_emails');
    $mkPlacement($runA, $unitMulti, 5);
    $ruAt20 = $mkPlacement($runA, $unitMulti, 20);
    $rsA = $mkRs($runA, 20); // participant "at" position 20, which hosts the unit
    $usMulti = $mkUs($unitMulti, $rsA); // run_unit_id NULL, live
    $unitSingle = $mkUnit('Email', 'survey_emails');
    $mkPlacement($runA, $unitSingle, 30);
    $rsA2 = $mkRs($runA, 30);
    $usSingle = $mkUs($unitSingle, $rsA2);
    $out = run_script('php ' . APPLICATION_ROOT . 'bin/backfill_run_unit_id_active.php --dry-run');
    ok(strpos($out, "unit_session {$usMulti} ") !== false,
        "multi-placement row {$usMulti} is in the manual-review skip list");
    ok(strpos($out, "unit_session {$usSingle} ") === false,
        "single-placement row {$usSingle} is backfillable (not skipped)");
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR(A): " . $e->getMessage() . "\n");
    $failures++;
}

try {
    echo "\n== B: sweep never stamps the pointer-designated current session ==\n";
    $runB = $mkRun('opsB', 1); // cron-active (sweep candidate scope)
    $unitP = $mkUnit('Pause', 'survey_pauses');
    $mkPlacement($runB, $unitP, 7);
    $rsB = $mkRs($runB, 99); // DRIFTED position: 99 hosts nothing
    $usB = $mkUs($unitP, $rsB, ['queued' => 0]); // live, queued=0 (stalled shape)
    $db->exec("UPDATE survey_run_sessions SET current_unit_session_id = :us WHERE id = :rs",
        ['us' => $usB, 'rs' => $rsB]);
    $out = run_script('php ' . APPLICATION_ROOT . "bin/sweep_stalled_unit_sessions.php --run-id={$runB} --min-age-minutes=1");
    $exp = $db->execute("SELECT expired FROM survey_unit_sessions WHERE id = :id", ['id' => $usB], true);
    ok($exp === null, "pointer-designated row {$usB} was NOT terminal-stamped (expired stays NULL)");
    ok(strpos($out, 'POINTER-PROTECTED') !== false, "sweep reports the row as pointer-protected");
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR(B): " . $e->getMessage() . "\n");
    $failures++;
}

try {
    echo "\n== C: healer defers to the engine's run-session lock, then heals ==\n";
    $runC = $mkRun('opsC');
    $unitC = $mkUnit('Pause', 'survey_pauses');
    $ruC = $mkPlacement($runC, $unitC, 3);
    $rsC = $mkRs($runC, 3);
    // duplicate cluster: two LIVE queued=1 rows, same placement, iteration NULL
    // (patch 063's UNIQUE permits NULL iterations — the legacy race shape)
    $usOld = $mkUs($unitC, $rsC, ['run_unit_id' => $ruC, 'queued' => 1]);
    $db->exec("UPDATE survey_unit_sessions SET created = created - INTERVAL 10 MINUTE WHERE id = :id", ['id' => $usOld]);
    $usNew = $mkUs($unitC, $rsC, ['run_unit_id' => $ruC, 'queued' => 1]);
    $db->exec("UPDATE survey_run_sessions SET current_unit_session_id = :us WHERE id = :rs",
        ['us' => $usNew, 'rs' => $rsC]); // pointer on the spurious newer row

    // an "engine" connection holds the run-session lock
    $cfg = Config::get('database');
    $engine = new PDO("mysql:host={$cfg['host']};dbname={$cfg['database']};charset=utf8mb4",
        $cfg['login'], $cfg['password']);
    $got = (int) $engine->query("SELECT GET_LOCK('run_session_{$rsC}', 2)")->fetchColumn();
    ok($got === 1, "smoke's engine connection holds run_session_{$rsC}");

    $out = run_script('php ' . APPLICATION_ROOT . "bin/heal_duplicate_pause_sessions.php --apply --run-id={$runC}");
    $q = (int) $db->execute("SELECT queued FROM survey_unit_sessions WHERE id = :id", ['id' => $usNew], true);
    $ptr = (int) $db->execute("SELECT current_unit_session_id FROM survey_run_sessions WHERE id = :id", ['id' => $rsC], true);
    ok($q === 1 && $ptr === $usNew, "nothing written while the engine held the lock (queued={$q}, pointer={$ptr})");

    $engine->query("SELECT RELEASE_LOCK('run_session_{$rsC}')");
    $out2 = run_script('php ' . APPLICATION_ROOT . "bin/heal_duplicate_pause_sessions.php --apply --run-id={$runC}");
    $q2 = (int) $db->execute("SELECT queued FROM survey_unit_sessions WHERE id = :id", ['id' => $usNew], true);
    $ptr2 = (int) $db->execute("SELECT current_unit_session_id FROM survey_run_sessions WHERE id = :id", ['id' => $rsC], true);
    ok($q2 === UnitSessionQueue::QUEUED_SUPERCEDED && $ptr2 === $usOld,
        "after release: spurious row superseded and pointer repointed to canonical (queued={$q2}, pointer={$ptr2})");
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR(C): " . $e->getMessage() . "\n");
    $failures++;
} finally {
    foreach ($rs_ids as $rs) {
        $db->exec("UPDATE survey_run_sessions SET current_unit_session_id = NULL WHERE id = :id", ['id' => $rs]);
        $db->exec("DELETE FROM survey_unit_sessions WHERE run_session_id = :id", ['id' => $rs]);
        $db->exec("DELETE FROM survey_run_sessions WHERE id = :id", ['id' => $rs]);
    }
    foreach ($run_ids as $rid) {
        $db->exec("DELETE FROM survey_run_units WHERE run_id = :id", ['id' => $rid]);
        $db->exec("DELETE FROM survey_run_metrics WHERE run_id = :id", ['id' => $rid]);
    }
    foreach ($unit_ids as $u) {
        $db->exec("DELETE FROM survey_emails WHERE id = :id", ['id' => $u]);
        $db->exec("DELETE FROM survey_pauses WHERE id = :id", ['id' => $u]);
        $db->exec("DELETE FROM survey_units WHERE id = :id", ['id' => $u]);
    }
    foreach ($run_ids as $rid) {
        $db->exec("DELETE FROM survey_runs WHERE id = :id", ['id' => $rid]);
    }
    if ($uid) {
        $db->exec("DELETE FROM survey_users WHERE id = :id", ['id' => $uid]);
    }
}

echo "\n" . ($failures === 0 ? "\e[32mALL PASSED\e[0m\n" : "\e[31m{$failures} FAILURE(S)\e[0m\n");
exit($failures === 0 ? 0 : 1);
