#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for the F7/F8/F22 pre-wipe expiry sweeps (review
 * 2026-07, item 13).
 *
 * Both sweeps — Run::replaceUnits' pre-wipe expiry and
 * RunUnit::endLiveSessionsAtPlacement (single-unit removal) — identified
 * affected live sessions via us.run_unit_id. Legacy sessions (pre-047, and
 * the 048 backfill's intentional NULLs for multi-position units) have
 * run_unit_id NULL, which no JOIN/equality can match: they survived the
 * wipe live and resumed spliced onto whatever unit later occupied their
 * stored position — the exact corruption those fixes exist to prevent, for
 * the oldest long-running participants.
 *
 * Asserts, per path, with one stamped and one legacy (NULL) live session:
 *  A. removeFromRun() expires BOTH (red pre-fix: legacy row survives);
 *  B. replaceUnits() expires BOTH (red pre-fix: legacy row survives).
 *
 * Usage:  docker exec formr_app php bin/test_structure_replace_smoke.php
 * Creates throwaway users/runs/units/sessions; removes them in finally.
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();
$failures = 0;
function ok($cond, string $label): void {
    global $failures;
    echo $cond ? "  \e[32mOK\e[0m  {$label}\n" : "  \e[31mFAIL\e[0m {$label}\n";
    if (!$cond) { $GLOBALS['failures']++; }
}

$uid = null; $run_ids = array(); $unit_ids = array(); $rs_ids = array();
$isExpired = function ($us_id) use ($db) {
    $row = $db->execute("SELECT expired FROM survey_unit_sessions WHERE id = :id", ['id' => $us_id], false, true);
    return is_array($row) && $row['expired'] !== null; // row must EXIST and be expired
};
try {
    $db->exec("INSERT INTO survey_users (user_code, email, created) VALUES (:c, :e, NOW())",
        ['c' => bin2hex(random_bytes(24)), 'e' => 'zzreplace' . getmypid() . '@example.invalid']);
    $uid = (int) $db->lastInsertId();

    // Email units use the BASE removeFromRun (the F22/F7 sweep applies);
    // Pause/Page/etc. override it to delete() the whole unit definition,
    // which FK-cascades the sessions away — a different, already-terminal path.
    $mkFixture = function (string $tag) use ($db, $uid, &$run_ids, &$unit_ids, &$rs_ids) {
        $db->exec("INSERT INTO survey_runs (user_id, created, modified, name, public, cron_active)
                   VALUES (:u, NOW(), NOW(), :n, 0, 0)", ['u' => $uid, 'n' => 'zz' . $tag . getmypid()]);
        $run_id = (int) $db->lastInsertId(); $run_ids[] = $run_id;
        $db->exec("INSERT INTO survey_units (type, created, modified) VALUES ('Email', NOW(), NOW())");
        $unit_id = (int) $db->lastInsertId(); $unit_ids[] = $unit_id;
        $db->exec("INSERT INTO survey_emails (id) VALUES (:id)", ['id' => $unit_id]);
        $db->exec("INSERT INTO survey_run_units (run_id, unit_id, position) VALUES (:r, :u, 1)",
            ['r' => $run_id, 'u' => $unit_id]);
        $ru_id = (int) $db->lastInsertId();
        $db->exec("INSERT INTO survey_run_sessions (run_id, session, created) VALUES (:r, :s, NOW())",
            ['r' => $run_id, 's' => bin2hex(random_bytes(32))]);
        $rs_id = (int) $db->lastInsertId(); $rs_ids[] = $rs_id;
        // one post-047 (stamped) and one legacy (run_unit_id NULL) LIVE session
        $db->exec("INSERT INTO survey_unit_sessions (unit_id, run_session_id, run_unit_id, created) VALUES (:u, :rs, :ru, NOW())",
            ['u' => $unit_id, 'rs' => $rs_id, 'ru' => $ru_id]);
        $stamped = (int) $db->lastInsertId();
        $db->exec("INSERT INTO survey_unit_sessions (unit_id, run_session_id, run_unit_id, created) VALUES (:u, :rs, NULL, NOW())",
            ['u' => $unit_id, 'rs' => $rs_id]);
        $legacy = (int) $db->lastInsertId();
        return [$run_id, $unit_id, $ru_id, $stamped, $legacy];
    };

    echo "== A: removeFromRun expires stamped AND legacy live sessions ==\n";
    list($runA, $unitA, $ruA, $stampedA, $legacyA) = $mkFixture('rmv');
    $run = new Run(null, $runA);
    $runUnit = RunUnitFactory::make($run, ['id' => $unitA, 'run_unit_id' => $ruA]);
    $runUnit->removeFromRun();
    ok($isExpired($stampedA), "stamped session expired");
    ok($isExpired($legacyA), "legacy (run_unit_id NULL) session expired too");

    echo "\n== B: replaceUnits expires stamped AND legacy live sessions ==\n";
    list($runB, $unitB, $ruB, $stampedB, $legacyB) = $mkFixture('rpl');
    $runObjB = new Run(null, $runB);
    $ok = $runObjB->replaceUnits('{"units":[{"type":"Pause","position":10}]}');
    ok($ok !== false, "replaceUnits imported the replacement structure");
    ok($isExpired($stampedB), "stamped session expired");
    ok($isExpired($legacyB), "legacy (run_unit_id NULL) session expired too");
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    $failures++;
} finally {
    foreach ($rs_ids as $rs) {
        $db->exec("DELETE FROM survey_unit_sessions WHERE run_session_id = :id", ['id' => $rs]);
        $db->exec("DELETE FROM survey_run_sessions WHERE id = :id", ['id' => $rs]);
    }
    foreach ($run_ids as $rid) {
        // pick up any units the replaceUnits import created for this run
        $imported = $db->execute("SELECT unit_id FROM survey_run_units WHERE run_id = :r", ['r' => $rid]);
        foreach ($imported as $row) {
            if ($row['unit_id']) { $GLOBALS['extra_units'][] = (int) $row['unit_id']; }
        }
        $db->exec("DELETE FROM survey_run_units WHERE run_id = :id", ['id' => $rid]);
        $db->exec("DELETE FROM survey_run_metrics WHERE run_id = :id", ['id' => $rid]);
    }
    foreach (array_merge($unit_ids, $GLOBALS['extra_units'] ?? array()) as $u) {
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
