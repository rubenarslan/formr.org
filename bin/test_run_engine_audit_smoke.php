#!/usr/bin/php
<?php
/**
 * Live-MariaDB e2e smoke for the 2026-07 run-engine audit fixes (v1.5.0).
 * See documentation/agent_doc/run_engine_audit_2026-07.md.
 *
 * Each section drives the REAL code against real rows and asserts the
 * post-fix behaviour. PHPUnit can't host these (SQLite bootstrap: no
 * ENUM/JSON/window surface, and several paths need concrete DB state).
 *
 * Covers: patch 064 (UNIQUE run_id,position), F10/F12 (reorder validation),
 * F1 (reminder-hijack placement guard + current-session resolution),
 * F2 (ended-terminal write guard), F13 (POST↔session binding), F17/F18
 * (Shuffle idempotency + stability), F20 (endLastExternal newest-only),
 * F4 (queue-deadline revalidation).
 *
 * Usage:  docker exec formr_app php bin/test_run_engine_audit_smoke.php
 * Exits 0 on success, non-zero otherwise. Cleans up its fixture rows.
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();
$failures = 0;
$ids = ['run' => null, 'units' => [], 'run_units' => [], 'sessions' => [], 'unit_sessions' => []];

function ok($cond, string $label): void {
    global $failures;
    if ($cond) { echo "  \e[32mOK\e[0m  {$label}\n"; }
    else { echo "  \e[31mFAIL\e[0m {$label}\n"; $failures++; }
}
function eq($actual, $expected, string $label): void {
    ok($actual === $expected, $label . ' (got ' . var_export($actual, true) . ', want ' . var_export($expected, true) . ')');
}

function mkUnit(DB $db, array &$ids, string $type): int {
    $uid = $db->insert('survey_units', ['type' => $type, 'created' => mysql_now(), 'modified' => mysql_now()]);
    $ids['units'][] = $uid;
    return $uid;
}
function mkPlacement(DB $db, array &$ids, int $unitId, int $position): int {
    $ruid = $db->insert('survey_run_units', ['run_id' => $ids['run'], 'unit_id' => $unitId, 'position' => $position]);
    $ids['run_units'][] = $ruid;
    return $ruid;
}
function mkSession(DB $db, array &$ids, int $position, $ended = null): array {
    $code = 'AUDIT' . bin2hex(random_bytes(8));
    $rsid = $db->insert('survey_run_sessions', [
        'run_id' => $ids['run'], 'session' => $code,
        'created' => mysql_now(), 'position' => $position, 'ended' => $ended,
    ]);
    $ids['sessions'][] = $rsid;
    return ['id' => $rsid, 'session' => $code];
}

function teardown(DB $db, array $ids): void {
    foreach ($ids['unit_sessions'] as $x) { try { $db->exec('DELETE FROM survey_unit_sessions WHERE id=:i', ['i' => $x]); } catch (Throwable $e) {} }
    foreach ($ids['sessions'] as $x)      { try { $db->exec('DELETE FROM survey_run_sessions WHERE id=:i', ['i' => $x]); } catch (Throwable $e) {} }
    foreach ($ids['run_units'] as $x)     { try { $db->exec('DELETE FROM survey_run_units WHERE id=:i', ['i' => $x]); } catch (Throwable $e) {} }
    foreach ($ids['units'] as $x)         { try { $db->exec('DELETE FROM survey_shuffles WHERE id=:i', ['i' => $x]); } catch (Throwable $e) {}
                                            try { $db->exec('DELETE FROM survey_units WHERE id=:i', ['i' => $x]); } catch (Throwable $e) {} }
    if ($ids['run']) { try { $db->exec('DELETE FROM survey_runs WHERE id=:i', ['i' => $ids['run']]); } catch (Throwable $e) {} }
}

try {
    $owner = $db->execute('SELECT id FROM survey_users ORDER BY id LIMIT 1', [], false, true);
    if (!$owner) { fwrite(STDERR, "No survey_users row to anchor the test run.\n"); exit(2); }
    $ids['run'] = $db->insert('survey_runs', [
        'user_id' => (int) $owner['id'], 'name' => 'audit_smoke_' . bin2hex(random_bytes(4)),
        'created' => mysql_now(), 'modified' => mysql_now(), 'cron_active' => 1,
    ]);
    $run = new Run(null, $ids['run']);

    // ── patch 064: UNIQUE(run_id, position) present ──────────────────────
    echo "\n== patch 064: UNIQUE(run_id, position) ==\n";
    $nonUnique = $db->execute(
        "SELECT NON_UNIQUE FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'survey_run_units'
           AND INDEX_NAME = 'position_run' LIMIT 1", [], true);
    if ($nonUnique === false) { fwrite(STDERR, "position_run index missing — apply patch 064.\n"); teardown($db, $ids); exit(3); }
    eq((int) $nonUnique, 0, 'position_run is UNIQUE');

    // ── F10/F12: Run::reorder rejects duplicate and non-positive positions ─
    echo "\n== F10/F12: reorder validation ==\n";
    $pU1 = mkUnit($db, $ids, 'Pause'); $pU2 = mkUnit($db, $ids, 'Pause');
    $pRU1 = mkPlacement($db, $ids, $pU1, 10); $pRU2 = mkPlacement($db, $ids, $pU2, 20);
    ok($run->reorder([$pRU1 => 30, $pRU2 => 30]) === false, 'reorder rejects duplicate positions');
    ok($run->reorder([$pRU1 => 0, $pRU2 => 40]) === false, 'reorder rejects position 0');
    ok($run->reorder([$pRU1 => -5, $pRU2 => 40]) === false, 'reorder rejects negative position');
    $stillPos = (int) $db->execute('SELECT position FROM survey_run_units WHERE id=:i', ['i' => $pRU1], true);
    eq($stillPos, 10, 'rejected reorder wrote nothing (position unchanged)');
    ok($run->reorder([$pRU1 => 15, $pRU2 => 25]) === true, 'reorder accepts distinct positive positions');
    eq((int) $db->execute('SELECT position FROM survey_run_units WHERE id=:i', ['i' => $pRU1], true), 15, 'valid reorder persisted');

    // ── F1: reminder create() at a foreign position keeps run_unit_id NULL ─
    echo "\n== F1: reminder-hijack placement guard ==\n";
    $survU = mkUnit($db, $ids, 'Survey');   $survRU = mkPlacement($db, $ids, $survU, 100);
    $emailU = mkUnit($db, $ids, 'Email');   $emailRU = mkPlacement($db, $ids, $emailU, 200);
    $rs = mkSession($db, $ids, 100);        // participant parked at the Survey (pos 100)
    $sess = new RunSession($rs['session'], $run);
    $sess->position = 100;
    // create the participant's real Survey session at pos 100
    $survRunUnit = RunUnitFactory::make($run, ['id' => $survU]);
    $survUS = new UnitSession($sess, $survRunUnit);
    $survUS->create();
    $ids['unit_sessions'][] = $survUS->id;
    eq((int) $db->execute('SELECT run_unit_id FROM survey_unit_sessions WHERE id=:i', ['i' => $survUS->id], true),
        $survRU, 'Survey session stamped with its own placement');
    // now the reminder path: create an Email session while parked at pos 100
    $emailRunUnit = RunUnitFactory::make($run, ['id' => $emailU]);
    $emailUS = new UnitSession($sess, $emailRunUnit);
    $emailUS->create(false);   // $new_current_unit = false, mirrors getReminderSession
    $ids['unit_sessions'][] = $emailUS->id;
    $emailRuId = $db->execute('SELECT run_unit_id FROM survey_unit_sessions WHERE id=:i', ['i' => $emailUS->id], true);
    ok($emailRuId === null, 'reminder Email session did NOT inherit the Pause/Survey placement (run_unit_id NULL)');
    // getCurrentUnitSession must resolve the Survey, not the newer Email
    $sess2 = new RunSession($rs['session'], $run);
    $sess2->position = 100;
    $current = $sess2->getCurrentUnitSession();
    ok($current && (int) $current->id === (int) $survUS->id, 'current session is the Survey, not the impostor Email');
    ok($current && $current->runUnit->type === 'Survey', 'current unit type is Survey');
    // and it is now fully hydrated (addendum): created is populated
    ok($current && $current->created !== null, 'current session is hydrated (created not null)');

    // ── F2 + F13: write guards on updateSurveyStudyRecord ────────────────
    echo "\n== F2/F13: survey write guards ==\n";
    // F2: an ended unit session refuses writes (guard fires before study access)
    $endedUS = new UnitSession($sess, $survRunUnit);
    $endedUS->id = $survUS->id;
    $endedUS->ended = mysql_now();
    ok($endedUS->updateSurveyStudyRecord(['some_item' => 1]) === false, 'ended session refuses answer write (F2)');
    $expiredUS = new UnitSession($sess, $survRunUnit);
    $expiredUS->id = $survUS->id;
    $expiredUS->expired = mysql_now();
    ok($expiredUS->updateSurveyStudyRecord(['some_item' => 1]) === false, 'expired session refuses answer write (F2)');
    // F13: a POST whose hidden session_id != this session is rejected
    $liveUS = new UnitSession($sess, $survRunUnit);
    $liveUS->id = $survUS->id;   // ended/expired stay null
    ok($liveUS->updateSurveyStudyRecord(['session_id' => $survUS->id + 999999, 'x' => 1]) === false,
        'mismatched session_id POST rejected (F13)');

    // ── F17/F18: Shuffle idempotent + stable per participant ─────────────
    echo "\n== F17/F18: Shuffle idempotency + stability ==\n";
    $shufU = mkUnit($db, $ids, 'Shuffle');
    $db->insert_update('survey_shuffles', ['id' => $shufU, 'groups' => 4]);
    $shufRU = mkPlacement($db, $ids, $shufU, 300);
    $shuffle = RunUnitFactory::make($run, ['id' => $shufU]);
    $shRs = mkSession($db, $ids, 300);
    $shSess = new RunSession($shRs['session'], $run);
    $shSess->position = 300;
    // first visit — a real unit session
    $shUS1 = new UnitSession($shSess, $shuffle); $shUS1->create(); $ids['unit_sessions'][] = $shUS1->id;
    $shuffle->getUnitSessionOutput($shUS1);
    $g1 = (int) $db->execute('SELECT `group` FROM shuffle WHERE session_id=:i', ['i' => $shUS1->id], true);
    ok($g1 >= 1 && $g1 <= 4, 'first shuffle drew a valid group');
    // idempotent: re-running getUnitSessionOutput on the SAME session must not throw and keep the group
    $shuffle->getUnitSessionOutput($shUS1);
    eq((int) $db->execute('SELECT `group` FROM shuffle WHERE session_id=:i', ['i' => $shUS1->id], true), $g1, 'F17: re-run keeps same group, no dup-key crash');
    // stability: a SECOND unit session (SkipBackward revisit) reuses the group
    $shUS2 = new UnitSession($shSess, $shuffle); $shUS2->create(); $ids['unit_sessions'][] = $shUS2->id;
    $shuffle->getUnitSessionOutput($shUS2);
    eq((int) $db->execute('SELECT `group` FROM shuffle WHERE session_id=:i', ['i' => $shUS2->id], true), $g1, 'F18: revisit reuses the participant group');

    // ── F20: endLastExternal ends only the newest live External ──────────
    echo "\n== F20: endLastExternal newest-only ==\n";
    $extU = mkUnit($db, $ids, 'External'); mkPlacement($db, $ids, $extU, 400);
    $extRs = mkSession($db, $ids, 400);
    $extA = $db->insert('survey_unit_sessions', ['run_session_id' => $extRs['id'], 'unit_id' => $extU, 'created' => mysql_now(), 'state' => UnitSessionQueue::STATE_PENDING]);
    $extB = $db->insert('survey_unit_sessions', ['run_session_id' => $extRs['id'], 'unit_id' => $extU, 'created' => mysql_now(), 'state' => UnitSessionQueue::STATE_PENDING]);
    $ids['unit_sessions'][] = $extA; $ids['unit_sessions'][] = $extB;
    $extSess = new RunSession($extRs['session'], $run);
    ok($extSess->endLastExternal() === true, 'endLastExternal reports success');
    $aEnded = $db->execute('SELECT ended FROM survey_unit_sessions WHERE id=:i', ['i' => $extA], true);
    $bEnded = $db->execute('SELECT ended FROM survey_unit_sessions WHERE id=:i', ['i' => $extB], true);
    ok($aEnded === null || $aEnded === false, 'older External still live');
    ok(!empty($bEnded), 'only the newest External was ended');

    // ── F4: queue-deadline revalidation (future re-arms, past ends) ──────
    echo "\n== F4: END-q deadline revalidation ==\n";
    $wU = mkUnit($db, $ids, 'Pause');
    $db->insert_update('survey_pauses', ['id' => $wU, 'wait_minutes' => 0]);
    mkPlacement($db, $ids, $wU, 500);
    $pause = RunUnitFactory::make($run, ['id' => $wU]);
    $qRs = mkSession($db, $ids, 500);
    $qSess = new RunSession($qRs['session'], $run);
    $qSess->position = 500;
    // future stored deadline + QUEUED_TO_END => revalidate should re-arm
    $futUS = new UnitSession($qSess, $pause); $futUS->create(); $ids['unit_sessions'][] = $futUS->id;
    $futUS->expires = mysql_datetime(strtotime('+2 hours'));
    $futUS->queued = UnitSessionQueue::QUEUED_TO_END;
    eq($futUS->revalidateQueueVerdict(), 'requeued', 'future deadline re-arms (not expired mid-work)');
    // past stored deadline + QUEUED_TO_END => revalidate should end
    $pastUS = new UnitSession($qSess, $pause); $pastUS->create(); $ids['unit_sessions'][] = $pastUS->id;
    $pastUS->expires = mysql_datetime(strtotime('-2 hours'));
    $pastUS->queued = UnitSessionQueue::QUEUED_TO_END;
    eq($pastUS->revalidateQueueVerdict(), 'end', 'past deadline ends');

} catch (Throwable $e) {
    fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    teardown($db, $ids);
    exit(2);
}

teardown($db, $ids);
echo $failures === 0 ? "\n\e[32mAll run-engine audit smoke checks passed.\e[0m\n" : "\n\e[31m{$failures} failure(s).\e[0m\n";
exit($failures === 0 ? 0 : 1);
