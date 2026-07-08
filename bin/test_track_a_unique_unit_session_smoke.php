#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for the duplicate-row forever-fix (v0.27.0, L2).
 *
 * Proves, end to end, that:
 *   (1) `idx_run_unit_iter` is UNIQUE (patch 049 applied), and
 *   (2) two CONCURRENT UnitSession::create() calls for the same placement
 *       leave exactly ONE row — the loser catches the 23000 and ADOPTS the
 *       winner's row instead of inserting a duplicate.
 *
 * The race is made deterministic with a "holder" child process: it INSERTs
 * the winning tuple in an OPEN transaction (holding the unique-key lock),
 * writes its row id to a ready-file, then commits after a short hold. The
 * main process waits for the ready-file, then calls the REAL create(),
 * whose INSERT blocks on the holder's uncommitted key and, once the holder
 * commits, fails with 23000 → adoption. So the main process is always the
 * loser and its create() must return the holder's id.
 *
 * PHPUnit can't host this (SQLite bootstrap, no real concurrency / UNIQUE).
 * Usage:  docker exec formr_app php bin/test_track_a_unique_unit_session_smoke.php
 * Exits 0 on success, non-zero otherwise. Cleans up its fixture rows.
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();

// ─── holder child: hold the winning tuple's unique-key lock, then commit ──
function arg_val($flag) {
    foreach ($GLOBALS['argv'] as $a) {
        if (strpos($a, $flag . '=') === 0) return substr($a, strlen($flag) + 1);
    }
    return null;
}
if (in_array('--hold', $argv, true)) {
    $rs = (int) arg_val('--rs'); $ru = (int) arg_val('--ru');
    $unit = (int) arg_val('--unit'); $ready = arg_val('--ready');
    $db->beginTransaction();
    $winnerId = $db->insert('survey_unit_sessions', [
        'run_session_id' => $rs, 'run_unit_id' => $ru, 'iteration' => 1,
        'unit_id' => $unit, 'created' => mysql_now(), 'state' => UnitSessionQueue::STATE_PENDING,
    ]);
    file_put_contents($ready, (string) $winnerId);   // id known pre-commit
    sleep(2);                                         // hold the lock
    $db->commit();
    exit(0);
}

// ─── main ────────────────────────────────────────────────────────────────
$failures = 0;
$artefacts = ['rs' => null, 'unit_ids' => [], 'run_unit_ids' => [], 'run_id' => null, 'ready' => null];

function teardown(DB $db, array &$artefacts): void {
    if (!empty($artefacts['ready']) && file_exists($artefacts['ready'])) { @unlink($artefacts['ready']); }
    if ($artefacts['rs']) { try { $db->exec('DELETE FROM survey_run_sessions WHERE id = :id', ['id' => $artefacts['rs']]); } catch (Throwable $e) {} }
    foreach ($artefacts['run_unit_ids'] as $ruid) { try { $db->exec('DELETE FROM survey_run_units WHERE id = :id', ['id' => $ruid]); } catch (Throwable $e) {} }
    foreach ($artefacts['unit_ids'] as $uid) { try { $db->exec('DELETE FROM survey_units WHERE id = :id', ['id' => $uid]); } catch (Throwable $e) {} }
    if ($artefacts['run_id']) { try { $db->exec('DELETE FROM survey_runs WHERE id = :id', ['id' => $artefacts['run_id']]); } catch (Throwable $e) {} }
}
function assert_eq($actual, $expected, string $label): void {
    global $failures;
    if ($actual === $expected) { echo "  \e[32mOK\e[0m  {$label}: " . var_export($actual, true) . "\n"; }
    else { echo "  \e[31mFAIL\e[0m {$label}: expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n"; $failures++; }
}

try {
    echo "== Duplicate-row forever-fix smoke (L2) ==\n";
    $owner = $db->execute('SELECT id FROM survey_users ORDER BY id LIMIT 1', [], false, true);
    if (!$owner) { fwrite(STDERR, "No survey_users row to anchor the test run.\n"); exit(2); }

    $artefacts['run_id'] = $db->insert('survey_runs', [
        'user_id' => (int) $owner['id'], 'name' => 'uniq_smoke_' . bin2hex(random_bytes(4)),
        'created' => mysql_now(), 'modified' => mysql_now(), 'cron_active' => 0,
    ]);
    $unitId = $db->insert('survey_units', ['type' => 'Pause', 'created' => mysql_now(), 'modified' => mysql_now()]);
    $artefacts['unit_ids'] = [$unitId];
    $ru = $db->insert('survey_run_units', ['run_id' => $artefacts['run_id'], 'unit_id' => $unitId, 'position' => 10]);
    $artefacts['run_unit_ids'] = [$ru];
    $artefacts['rs'] = $db->insert('survey_run_sessions', [
        'run_id' => $artefacts['run_id'], 'session' => 'UNIQ' . bin2hex(random_bytes(8)),
        'created' => mysql_now(), 'position' => 10,
    ]);

    echo "\n-- (1) idx_run_unit_iter is UNIQUE (patch 049 applied) --\n";
    $nonUnique = $db->execute(
        "SELECT NON_UNIQUE FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'survey_unit_sessions'
           AND INDEX_NAME = 'idx_run_unit_iter' LIMIT 1", [], true);
    if ($nonUnique === false || (int) $nonUnique !== 0) {
        fwrite(STDERR, "idx_run_unit_iter is NOT unique — apply sql/patches/049_unique_run_unit_iter.sql first.\n");
        teardown($db, $artefacts); exit(3);
    }
    assert_eq((int) $nonUnique, 0, 'idx_run_unit_iter NON_UNIQUE');

    echo "\n-- (2) concurrent create(): loser adopts the winner's row --\n";
    $artefacts['ready'] = sys_get_temp_dir() . '/uniq_smoke_ready_' . getmypid();
    @unlink($artefacts['ready']);
    $devnull = ['file', '/dev/null', 'w'];
    $holder = proc_open(
        ['php', __FILE__, '--hold', '--rs=' . $artefacts['rs'], '--ru=' . $ru,
         '--unit=' . $unitId, '--ready=' . $artefacts['ready']],
        [0 => ['file', '/dev/null', 'r'], 1 => $devnull, 2 => $devnull], $pipes);
    if (!is_resource($holder)) { throw new Exception('could not spawn holder process'); }

    // Wait until the holder has INSERTed the winner (still uncommitted) and
    // published its id — so the main create() below is guaranteed to collide.
    $deadline = time() + 8;
    while (!(file_exists($artefacts['ready']) && filesize($artefacts['ready']) > 0) && time() < $deadline) {
        usleep(50000);
    }
    $winnerId = (int) trim((string) @file_get_contents($artefacts['ready']));
    if (!$winnerId) { proc_terminate($holder); throw new Exception('holder never published a winner id'); }

    // The REAL create(): its INSERT blocks on the holder's uncommitted key,
    // then gets 23000 when the holder commits, then adopts.
    $run = new Run(null, $artefacts['run_id']);
    $rsRow = $db->execute('SELECT session FROM survey_run_sessions WHERE id = :id', ['id' => $artefacts['rs']], false, true);
    $rs = new RunSession($rsRow['session'], $run);
    $rs->position = 10;
    $pauseRunUnit = RunUnitFactory::make($run, ['id' => $unitId]);

    $us = new UnitSession($rs, $pauseRunUnit);
    $us->create();
    proc_close($holder);   // holder has committed by now

    $rowCount = (int) $db->execute(
        'SELECT COUNT(*) FROM survey_unit_sessions WHERE run_session_id = :rs AND run_unit_id = :ru',
        ['rs' => $artefacts['rs'], 'ru' => $ru], true);
    $current = (int) $db->execute('SELECT current_unit_session_id FROM survey_run_sessions WHERE id = :id',
        ['id' => $artefacts['rs']], true);
    $survivorIter = (int) $db->execute('SELECT iteration FROM survey_unit_sessions WHERE id = :id',
        ['id' => $winnerId], true);

    assert_eq($rowCount, 1, 'exactly one unit-session row for the placement');
    assert_eq((int) $us->id, $winnerId, "loser's create() adopted the winner's id");
    assert_eq($us->valid, true, 'adopted UnitSession is valid');
    assert_eq($current, $winnerId, 'run session current_unit_session_id points at the survivor');
    assert_eq($survivorIter, 1, 'survivor keeps iteration 1 (no phantom iteration 2)');

    echo "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    teardown($db, $artefacts);
    exit(2);
}

teardown($db, $artefacts);
echo $failures === 0 ? "\n\e[32mAll duplicate-row forever-fix checks passed.\e[0m\n" : "\n\e[31m{$failures} failures.\e[0m\n";
exit($failures === 0 ? 0 : 1);
