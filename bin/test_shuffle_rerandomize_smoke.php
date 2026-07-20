#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for Shuffle per-revisit randomization (review 2026-07,
 * item 12 — maintainer decision: revert audit F18's group reuse).
 *
 * BY DESIGN, each revisit of a Shuffle unit (each new unit session, e.g. via
 * SkipBackward loops) draws a FRESH random group — designs wanting a constant
 * experimental group keep the Shuffle outside the loop. The F18 "stability"
 * lookup (reuse the first group ever drawn for this participant + unit) was a
 * mistake and is removed. What stays from that commit is the F17 crash fix:
 * re-executing the SAME unit session (crash between INSERT and end()) must
 * not fatal on the shuffle PK, and keeps its originally stored group.
 *
 * Asserts:
 *  A. a second unit session draws independently: a planted out-of-range
 *     group (99) on visit 1 is NOT copied to visit 2 (red pre-revert: the
 *     reuse lookup returns 99);
 *  B. re-executing the SAME unit session neither fatals nor re-draws — the
 *     stored group survives and is what the unit reports.
 *
 * Usage:  docker exec formr_app php bin/test_shuffle_rerandomize_smoke.php
 * Creates a throwaway user/run/shuffle unit/sessions; removes them in finally.
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();
$failures = 0;
function ok($cond, string $label): void {
    global $failures;
    echo $cond ? "  \e[32mOK\e[0m  {$label}\n" : "  \e[31mFAIL\e[0m {$label}\n";
    if (!$cond) { $GLOBALS['failures']++; }
}

$uid = null; $run_id = null; $unit_id = null; $rs_id = null; $us_ids = array();
try {
    $db->exec("INSERT INTO survey_users (user_code, email, created) VALUES (:c, :e, NOW())",
        ['c' => bin2hex(random_bytes(24)), 'e' => 'zzshuffle' . getmypid() . '@example.invalid']);
    $uid = (int) $db->lastInsertId();
    $db->exec("INSERT INTO survey_runs (user_id, created, modified, name, public, cron_active)
               VALUES (:u, NOW(), NOW(), :n, 0, 0)", ['u' => $uid, 'n' => 'zzshuffle' . getmypid()]);
    $run_id = (int) $db->lastInsertId();
    $db->exec("INSERT INTO survey_units (type, created, modified) VALUES ('Shuffle', NOW(), NOW())");
    $unit_id = (int) $db->lastInsertId();
    $db->exec("INSERT INTO survey_shuffles (id, `groups`) VALUES (:id, 2)", ['id' => $unit_id]);
    $db->exec("INSERT INTO survey_run_units (run_id, unit_id, position) VALUES (:r, :u, 1)",
        ['r' => $run_id, 'u' => $unit_id]);

    $session = bin2hex(random_bytes(32));
    $db->exec("INSERT INTO survey_run_sessions (run_id, session, created) VALUES (:r, :s, NOW())",
        ['r' => $run_id, 's' => $session]);
    $rs_id = (int) $db->lastInsertId();

    $run = new Run(null, $run_id);
    $runSession = new RunSession($session, $run, ['user' => new User(null, null, ['cron' => true])]);
    $shuffle = RunUnitFactory::make($run, ['id' => $unit_id]);

    $mkUnitSession = function () use ($db, $unit_id, $rs_id, $runSession, &$us_ids) {
        $db->exec("INSERT INTO survey_unit_sessions (unit_id, run_session_id, created) VALUES (:u, :rs, NOW())",
            ['u' => $unit_id, 'rs' => $rs_id]);
        $id = (int) $db->lastInsertId();
        $us_ids[] = $id;
        return new UnitSession($runSession, null, ['id' => $id, 'load' => true]);
    };
    $storedGroup = function ($us_id) use ($db) {
        return $db->execute("SELECT `group` FROM shuffle WHERE session_id = :id", ['id' => $us_id], true);
    };

    echo "== A: each revisit draws independently (by design) ==\n";
    $us1 = $mkUnitSession();
    $shuffle->getUnitSessionOutput($us1);
    // plant an impossible marker group on visit 1: if visit 2 shows 99, it
    // was REUSED, not drawn (selectRandomGroup only yields 1..2)
    $db->exec("UPDATE shuffle SET `group` = 99 WHERE session_id = :id", ['id' => $us1->id]);
    $us2 = $mkUnitSession();
    $shuffle->getUnitSessionOutput($us2);
    $g2 = (int) $storedGroup($us2->id);
    ok($g2 >= 1 && $g2 <= 2, "visit 2 drew a fresh group in range ({$g2}), not the planted 99");

    echo "\n== B: same-session retry is idempotent (F17 kept) ==\n";
    $before = (int) $storedGroup($us2->id);
    $threw = null;
    try {
        $out = $shuffle->getUnitSessionOutput($us2); // re-execution of the SAME session
    } catch (Throwable $e) {
        $threw = $e;
    }
    ok($threw === null, "re-execution does not fatal on the shuffle PK");
    ok((int) $storedGroup($us2->id) === $before, "stored group unchanged on retry ({$before})");
    ok(isset($out['log']['result_log']) || isset($out['log']),
        "retry reports a group (log present)");
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    $failures++;
} finally {
    foreach ($us_ids as $us) {
        $db->exec("DELETE FROM shuffle WHERE session_id = :id", ['id' => $us]);
    }
    if ($rs_id) {
        $db->exec("DELETE FROM survey_unit_sessions WHERE run_session_id = :id", ['id' => $rs_id]);
        $db->exec("DELETE FROM survey_run_sessions WHERE id = :id", ['id' => $rs_id]);
    }
    if ($run_id) {
        $db->exec("DELETE FROM survey_run_units WHERE run_id = :id", ['id' => $run_id]);
        $db->exec("DELETE FROM survey_run_metrics WHERE run_id = :id", ['id' => $run_id]);
    }
    if ($unit_id) {
        $db->exec("DELETE FROM survey_shuffles WHERE id = :id", ['id' => $unit_id]);
        $db->exec("DELETE FROM survey_units WHERE id = :id", ['id' => $unit_id]);
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
