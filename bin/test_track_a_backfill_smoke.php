#!/usr/bin/php
<?php
/**
 * Track A backfill smoke check: seeds historical-shape data with
 * run_unit_id IS NULL / iteration IS NULL, runs the 048 backfill, and
 * asserts the resolution / ambiguity-flagging logic. PHPUnit doesn't
 * host this for the same reason as test_track_a_smoke.php — the SQLite
 * test bootstrap doesn't carry the JSON / ENUM / window-function
 * surface the live MariaDB has.
 *
 * Usage:
 *   docker exec formr_app php bin/test_track_a_backfill_smoke.php
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();
$failures = 0;
$run_a = $run_b = null;
$artefacts = ['unit_ids' => [], 'rs_ids' => []];

function assert_eq($actual, $expected, string $label): void {
    global $failures;
    if ($actual === $expected) {
        echo "  \e[32mOK\e[0m  {$label}: " . var_export($actual, true) . "\n";
    } else {
        echo "  \e[31mFAIL\e[0m {$label}: expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n";
        $failures++;
    }
}

function teardown(DB $db, $run_a, $run_b, array &$artefacts): void {
    foreach ($artefacts['rs_ids'] as $rsid) {
        try { $db->exec('DELETE FROM survey_run_sessions WHERE id = :id', ['id' => $rsid]); } catch (Throwable $e) {}
    }
    foreach ([$run_a, $run_b] as $rid) {
        if ($rid !== null) {
            try { $db->exec('DELETE FROM survey_run_units WHERE run_id = :rid', ['rid' => $rid]); } catch (Throwable $e) {}
            try { $db->exec('DELETE FROM survey_runs WHERE id = :rid', ['rid' => $rid]); } catch (Throwable $e) {}
        }
    }
    foreach ($artefacts['unit_ids'] as $uid) {
        try { $db->exec('DELETE FROM survey_units WHERE id = :id', ['id' => $uid]); } catch (Throwable $e) {}
    }
}

try {
    echo "== Track A backfill smoke ==\n";
    $owner = $db->execute('SELECT id FROM survey_users ORDER BY id LIMIT 1', [], false, true);
    if (!$owner) {
        fwrite(STDERR, "No survey_users row.\n");
        exit(2);
    }

    // Run A: unique-position usage. Pause at position 10, Survey at 20.
    $run_a = $db->insert('survey_runs', [
        'user_id' => (int) $owner['id'],
        'name' => 'track_a_bf_uniq_' . bin2hex(random_bytes(4)),
        'created' => mysql_now(), 'modified' => mysql_now(),
        'cron_active' => 0,
    ]);

    // Run B: same Survey unit reused at positions 30 AND 40 (multi-position).
    $run_b = $db->insert('survey_runs', [
        'user_id' => (int) $owner['id'],
        'name' => 'track_a_bf_multi_' . bin2hex(random_bytes(4)),
        'created' => mysql_now(), 'modified' => mysql_now(),
        'cron_active' => 0,
    ]);

    $unit_pause  = $db->insert('survey_units', ['type' => 'Pause',  'created' => mysql_now(), 'modified' => mysql_now()]);
    $unit_survey = $db->insert('survey_units', ['type' => 'Survey', 'created' => mysql_now(), 'modified' => mysql_now()]);
    $unit_reused = $db->insert('survey_units', ['type' => 'Survey', 'created' => mysql_now(), 'modified' => mysql_now()]);
    $artefacts['unit_ids'] = [$unit_pause, $unit_survey, $unit_reused];

    $ru_a_pause  = $db->insert('survey_run_units', ['run_id' => $run_a, 'unit_id' => $unit_pause,  'position' => 10]);
    $ru_a_survey = $db->insert('survey_run_units', ['run_id' => $run_a, 'unit_id' => $unit_survey, 'position' => 20]);

    // Run B: same unit_reused at positions 30 AND 40 — multi-position.
    $ru_b_30 = $db->insert('survey_run_units', ['run_id' => $run_b, 'unit_id' => $unit_reused, 'position' => 30]);
    $ru_b_40 = $db->insert('survey_run_units', ['run_id' => $run_b, 'unit_id' => $unit_reused, 'position' => 40]);

    // Run sessions.
    $rs_a = $db->insert('survey_run_sessions', [
        'run_id' => $run_a,
        'session' => 'BFAXXX' . bin2hex(random_bytes(8)),
        'created' => mysql_now(),
        'position' => 20,
    ]);
    $rs_b = $db->insert('survey_run_sessions', [
        'run_id' => $run_b,
        'session' => 'BFBXXX' . bin2hex(random_bytes(8)),
        'created' => mysql_now(),
        'position' => 40,
    ]);
    $artefacts['rs_ids'] = [$rs_a, $rs_b];

    // Insert historical-shape unit-sessions: run_unit_id NULL, iteration NULL,
    // simulating pre-047 rows. Two pause iterations and one survey for run A.
    // Two iterations of the reused unit for run B.
    $us_a_p1 = $db->insert('survey_unit_sessions', ['run_session_id' => $rs_a, 'unit_id' => $unit_pause,  'created' => mysql_now()]);
    $us_a_p2 = $db->insert('survey_unit_sessions', ['run_session_id' => $rs_a, 'unit_id' => $unit_pause,  'created' => mysql_now()]);
    $us_a_s1 = $db->insert('survey_unit_sessions', ['run_session_id' => $rs_a, 'unit_id' => $unit_survey, 'created' => mysql_now()]);

    $us_b_1 = $db->insert('survey_unit_sessions', ['run_session_id' => $rs_b, 'unit_id' => $unit_reused, 'created' => mysql_now()]);
    $us_b_2 = $db->insert('survey_unit_sessions', ['run_session_id' => $rs_b, 'unit_id' => $unit_reused, 'created' => mysql_now()]);

    // Wipe the post-A2 defaults on these rows so they look pre-A2 — A2's
    // create() set iteration=1/state=PENDING; the backfill expects those
    // to be NULL/PENDING-ish on the historical tail.
    $db->exec(
        'UPDATE survey_unit_sessions SET run_unit_id = NULL, iteration = NULL, state = NULL, state_log = NULL
         WHERE id IN (:a, :b, :c, :d, :e)',
        ['a' => $us_a_p1, 'b' => $us_a_p2, 'c' => $us_a_s1, 'd' => $us_b_1, 'e' => $us_b_2]
    );

    echo "\n-- Apply backfill (048) inline --\n";
    $sql = file_get_contents('/var/www/formr/sql/patches/048_uxec_track_a_backfill.sql');
    // Strip line-comments — the bare ';' splitter doesn't reason about
    // them, so leaving them in confuses statement boundaries.
    $sql = preg_replace('/^--[^\n]*$/m', '', $sql);
    foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        $db->exec($stmt);
    }
    echo "  applied 3 backfill statements\n";

    echo "\n-- Run A (unique-position): all 3 rows backfilled, iterations correct --\n";
    $rows = $db->execute(
        'SELECT id, run_unit_id, iteration, state_log FROM survey_unit_sessions WHERE id IN (:a, :b, :c) ORDER BY id',
        ['a' => $us_a_p1, 'b' => $us_a_p2, 'c' => $us_a_s1]
    );
    assert_eq((int) $rows[0]['run_unit_id'], (int) $ru_a_pause,  'first pause: run_unit_id resolves to pause-position-10');
    assert_eq((int) $rows[0]['iteration'],   1,                  'first pause: iteration=1');
    assert_eq((int) $rows[1]['run_unit_id'], (int) $ru_a_pause,  'second pause: run_unit_id resolves to pause-position-10');
    assert_eq((int) $rows[1]['iteration'],   2,                  'second pause: iteration=2');
    assert_eq((int) $rows[2]['run_unit_id'], (int) $ru_a_survey, 'survey: run_unit_id resolves to survey-position-20');
    assert_eq((int) $rows[2]['iteration'],   1,                  'survey: iteration=1');
    assert_eq($rows[0]['state_log'], null, 'first pause has no ambiguity flag');

    echo "\n-- Run B (multi-position): both rows stay NULL with state_log ambiguity --\n";
    $rows = $db->execute(
        'SELECT id, run_unit_id, iteration, state_log FROM survey_unit_sessions WHERE id IN (:a, :b) ORDER BY id',
        ['a' => $us_b_1, 'b' => $us_b_2]
    );
    assert_eq($rows[0]['run_unit_id'], null, 'multi-position row 1: run_unit_id stays NULL');
    assert_eq($rows[1]['run_unit_id'], null, 'multi-position row 2: run_unit_id stays NULL');
    $log = json_decode($rows[0]['state_log'], true);
    assert_eq(is_array($log) && ($log['backfill'] ?? null) === 'run_unit_id_ambiguous', true, 'state_log carries backfill ambiguity flag');
    assert_eq((int) $rows[0]['iteration'], 1, 'multi-position row 1: iteration=1 (best-effort by unit_id)');
    assert_eq((int) $rows[1]['iteration'], 2, 'multi-position row 2: iteration=2');

    echo "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    teardown($db, $run_a, $run_b, $artefacts);
    exit(2);
}

teardown($db, $run_a, $run_b, $artefacts);
echo $failures === 0 ? "\n\e[32mAll Track A backfill smoke checks passed.\e[0m\n" : "\n\e[31m{$failures} failures.\e[0m\n";
exit($failures === 0 ? 0 : 1);
