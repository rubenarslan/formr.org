#!/usr/bin/php
<?php
/**
 * Track A end-to-end smoke check against the live MariaDB.
 *
 * Verifies that UnitSession::create populates `run_unit_id`, `iteration`,
 * `state` (PENDING) for new rows, that `addItem` flips state to
 * WAITING_USER / WAITING_TIMER, that `end()` / `expire()` set ENDED /
 * EXPIRED, and that the supersede side-effect of `create()` flips prior
 * siblings to SUPERSEDED. PHPUnit can't host this because the test
 * bootstrap forces SQLite in-memory, which doesn't carry the MariaDB-
 * specific ENUM column.
 *
 * Usage:
 *   docker exec formr_app php bin/test_track_a_smoke.php
 *
 * Exits 0 on success, non-zero on first assertion failure. Cleans up
 * its own fixture rows on exit (success or failure).
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();

// Self-contained ANSI-coloured assertion helper. Bails on first failure
// after attempting cleanup.
$failures = 0;
$artefacts = ['rs' => null, 'unit_ids' => [], 'run_unit_ids' => [], 'run_id' => null];

function teardown(DB $db, array &$artefacts): void {
    if ($artefacts['rs']) {
        // ON DELETE CASCADE wipes survey_unit_sessions for this rs.
        try { $db->exec('DELETE FROM survey_run_sessions WHERE id = :id', ['id' => $artefacts['rs']]); } catch (Throwable $e) {}
    }
    foreach ($artefacts['run_unit_ids'] as $ruid) {
        try { $db->exec('DELETE FROM survey_run_units WHERE id = :id', ['id' => $ruid]); } catch (Throwable $e) {}
    }
    foreach ($artefacts['unit_ids'] as $uid) {
        try { $db->exec('DELETE FROM survey_units WHERE id = :id', ['id' => $uid]); } catch (Throwable $e) {}
    }
    if ($artefacts['run_id']) {
        try { $db->exec('DELETE FROM survey_runs WHERE id = :id', ['id' => $artefacts['run_id']]); } catch (Throwable $e) {}
    }
}

function assert_eq($actual, $expected, string $label): void {
    global $failures;
    if ($actual === $expected) {
        echo "  \e[32mOK\e[0m  {$label}: " . var_export($actual, true) . "\n";
    } else {
        echo "  \e[31mFAIL\e[0m {$label}: expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n";
        $failures++;
    }
}

try {
    echo "== Track A smoke ==\n";

    // Find an existing user to anchor a fresh Run. Production data on
    // this dev DB has at least the formr admin.
    $owner = $db->execute('SELECT id FROM survey_users ORDER BY id LIMIT 1', [], false, true);
    if (!$owner) {
        fwrite(STDERR, "No survey_users row to anchor the test run.\n");
        exit(2);
    }

    // Create a transient Run.
    $artefacts['run_id'] = $db->insert('survey_runs', [
        'user_id'     => (int) $owner['id'],
        'name'        => 'track_a_smoke_' . bin2hex(random_bytes(4)),
        'created'     => mysql_now(),
        'modified'    => mysql_now(),
        'cron_active' => 0,
    ]);

    // Create two transient unit definitions and place them at positions
    // 10 and 20 — so `getRunUnitIdAtPosition($position)` resolves them.
    // survey_units columns are minimal: id, created, modified, type, form_study_id.
    $unit1Id = $db->insert('survey_units', ['type' => 'Pause',  'created' => mysql_now(), 'modified' => mysql_now()]);
    $unit2Id = $db->insert('survey_units', ['type' => 'Survey', 'created' => mysql_now(), 'modified' => mysql_now()]);
    $artefacts['unit_ids'] = [$unit1Id, $unit2Id];

    $ru1 = $db->insert('survey_run_units', ['run_id' => $artefacts['run_id'], 'unit_id' => $unit1Id, 'position' => 10]);
    $ru2 = $db->insert('survey_run_units', ['run_id' => $artefacts['run_id'], 'unit_id' => $unit2Id, 'position' => 20]);
    $artefacts['run_unit_ids'] = [$ru1, $ru2];

    // Create a transient run_session at position 10.
    $artefacts['rs'] = $db->insert('survey_run_sessions', [
        'run_id'   => $artefacts['run_id'],
        'session'  => 'TRACKAXXX' . bin2hex(random_bytes(8)),
        'created'  => mysql_now(),
        'position' => 10,
    ]);

    // ─── Construct domain objects pointing at the fixture ───
    // Run::__construct($name = null, $id = null)
    $run = new Run(null, $artefacts['run_id']);
    $runSession = $db->execute('SELECT session FROM survey_run_sessions WHERE id = :id', ['id' => $artefacts['rs']], false, true);
    $rs = new RunSession($runSession['session'], $run);
    $rs->position = 10;

    $pauseRunUnit = RunUnitFactory::make($run, ['id' => $unit1Id]);
    $surveyRunUnit = RunUnitFactory::make($run, ['id' => $unit2Id]);

    echo "\n-- A2: UnitSession::create writes run_unit_id, iteration, state=PENDING --\n";
    $us1 = new UnitSession($rs, $pauseRunUnit);
    $us1->create();

    $row = $db->execute('SELECT run_unit_id, iteration, state FROM survey_unit_sessions WHERE id = :id', ['id' => $us1->id], false, true);
    assert_eq((int) $row['run_unit_id'], (int) $ru1, 'run_unit_id matches survey_run_units(id)');
    assert_eq((int) $row['iteration'], 1, 'iteration starts at 1');
    assert_eq($row['state'], 'PENDING', 'state is PENDING after create');

    echo "\n-- A2: addItem dual-writes state=WAITING_TIMER for Pause --\n";
    UnitSessionQueue::addItem($us1, $pauseRunUnit, ['expires' => time() + 3600, 'queued' => 2]);
    $row = $db->execute('SELECT queued, state FROM survey_unit_sessions WHERE id = :id', ['id' => $us1->id], false, true);
    assert_eq((int) $row['queued'], 2, 'queued is 2 after addItem');
    assert_eq($row['state'], 'WAITING_TIMER', 'Pause goes to WAITING_TIMER');

    echo "\n-- A2 + A6: end() sets state=ENDED and state_log JSON --\n";
    $us1->result_log = 'unit test reason';
    $us1->end('test_pause_ended');
    $row = $db->execute('SELECT state, ended, state_log FROM survey_unit_sessions WHERE id = :id', ['id' => $us1->id], false, true);
    assert_eq($row['state'], 'ENDED', 'state is ENDED after end()');
    assert_eq(!empty($row['ended']), true, 'ended timestamp is set');
    $log = json_decode((string) $row['state_log'], true);
    assert_eq(is_array($log), true, 'state_log is valid JSON');
    assert_eq($log['reason'] ?? null, 'test_pause_ended', 'state_log.reason matches end() reason');
    assert_eq($log['ctx']['unit_type'] ?? null, 'Pause', 'state_log.ctx.unit_type matches Pause');
    assert_eq($log['ctx']['msg'] ?? null, 'unit test reason', 'state_log.ctx.msg carries result_log');

    echo "\n-- A2: Survey at the same position increments iteration to 2 (this would be a back-jump scenario) --\n";
    // Re-position to 20 (the survey), then go BACK to 10 (Pause) — simulates SkipBackward.
    $rs->position = 20;
    $us2 = new UnitSession($rs, $surveyRunUnit);
    $us2->create();
    $row = $db->execute('SELECT run_unit_id, iteration FROM survey_unit_sessions WHERE id = :id', ['id' => $us2->id], false, true);
    assert_eq((int) $row['run_unit_id'], (int) $ru2, 'survey run_unit_id resolves to ru2');
    assert_eq((int) $row['iteration'], 1, 'first iteration of survey at position 20');

    // Now back to position 10 (Pause again, second iteration)
    $rs->position = 10;
    $us3 = new UnitSession($rs, $pauseRunUnit);
    $us3->create();
    $row = $db->execute('SELECT run_unit_id, iteration FROM survey_unit_sessions WHERE id = :id', ['id' => $us3->id], false, true);
    assert_eq((int) $row['run_unit_id'], (int) $ru1, 'back-jump pause references same run_unit_id');
    assert_eq((int) $row['iteration'], 2, 'iteration increments to 2 for second pause attempt');

    echo "\n-- A2: addItem on Survey writes state=WAITING_USER --\n";
    UnitSessionQueue::addItem($us2, $surveyRunUnit, ['expires' => time() + 3600, 'queued' => 2]);
    $row = $db->execute('SELECT state FROM survey_unit_sessions WHERE id = :id', ['id' => $us2->id], false, true);
    assert_eq($row['state'], 'WAITING_USER', 'Survey goes to WAITING_USER');

    echo "\n-- A2 + A6: expire() sets state=EXPIRED and state_log JSON --\n";
    $us2->expire();
    $row = $db->execute('SELECT state, expired, state_log FROM survey_unit_sessions WHERE id = :id', ['id' => $us2->id], false, true);
    assert_eq($row['state'], 'EXPIRED', 'state is EXPIRED after expire()');
    assert_eq(!empty($row['expired']), true, 'expired timestamp is set');
    $log = json_decode((string) $row['state_log'], true);
    assert_eq($log['reason'] ?? null, 'expired', 'expire() state_log.reason is "expired"');
    assert_eq($log['ctx']['unit_type'] ?? null, 'Survey', 'expire() state_log.ctx.unit_type is Survey');

    echo "\n-- A2: supersede side-effect of create() sets state=SUPERSEDED on prior queued sibling --\n";
    // Queue us3 (the second pause iteration), then create a 3rd pause — this should supersede us3.
    UnitSessionQueue::addItem($us3, $pauseRunUnit, ['expires' => time() + 3600, 'queued' => 2]);
    $us4 = new UnitSession($rs, $pauseRunUnit);
    $us4->create();
    $row = $db->execute('SELECT state, queued FROM survey_unit_sessions WHERE id = :id', ['id' => $us3->id], false, true);
    assert_eq((int) $row['queued'], -9, 'us3 queued flipped to -9 by supersede');
    assert_eq($row['state'], 'SUPERSEDED', 'us3 state flipped to SUPERSEDED');

    echo "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    teardown($db, $artefacts);
    exit(2);
}

teardown($db, $artefacts);
echo $failures === 0 ? "\n\e[32mAll Track A smoke checks passed.\e[0m\n" : "\n\e[31m{$failures} failures.\e[0m\n";
exit($failures === 0 ? 0 : 1);
