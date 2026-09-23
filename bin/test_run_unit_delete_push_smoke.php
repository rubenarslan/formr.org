#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for the v1.10.4 regression: an admin "send to position"
 * push, and a participant whose current unit was deleted, must both keep
 * working. v1.10.3 stripped `valid` off every find()-hydrated unit, so
 * RunSession::runTo() rejected a perfectly-placed unit with "Could not create
 * unit session for unit N at pos. M" and a participant could stall.
 *
 * Can't live in the SQLite unit lane: forceTo() takes the run-session lock
 * via GET_LOCK (MySQL only). The root cause is pinned there in
 * tests/RunUnitValidHydrationTest.php; this is the end-to-end guard.
 *
 * Usage:  docker exec formr_app php bin/test_run_unit_delete_push_smoke.php
 * Exits 0 on success. Builds a throwaway run + units + participant and
 * deletes them all in a finally block; touches no real data.
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();
$failures = 0;
function ok($cond, string $label): void {
    global $failures;
    echo $cond ? "  \e[32mOK\e[0m  {$label}\n" : "  \e[31mFAIL\e[0m {$label}\n";
    if (!$cond) { $GLOBALS['failures']++; }
}
function alertsText(): string {
    $out = [];
    foreach (Site::getInstance()->alerts as $a) { $out[] = strip_tags(is_array($a) ? json_encode($a) : (string) $a); }
    return implode(' | ', $out);
}

$owner_id = (int) $db->execute("SELECT id FROM survey_users WHERE user_code IS NOT NULL ORDER BY id LIMIT 1", array(), true);
if (!$owner_id) { fwrite(STDERR, "no user to own the test run\n"); exit(1); }

$run_id = $participant_id = null;
$participant = 'zzdp' . getmypid() . substr(md5(uniqid('', true)), 0, 24);
try {
    // ── run + three real units: Pause@10 (deleted under the participant),
    //    Pause@20 (where they should land), Page@30 (a valid tail target) ──
    $run_name = 'zzdelpush' . getmypid();
    $db->exec(
        "INSERT INTO survey_runs (user_id, created, modified, name, public, cron_active)
         VALUES (:uid, NOW(), NOW(), :name, 0, 0)",
        array('uid' => $owner_id, 'name' => $run_name)
    );
    $run_id = (int) $db->lastInsertId();

    $mkUnit = function (string $type) use ($db) {
        $db->exec("INSERT INTO survey_units (type, created, modified) VALUES (:t, NOW(), NOW())", array('t' => $type));
        return (int) $db->lastInsertId();
    };
    $u10 = $mkUnit('Pause');
    $u20 = $mkUnit('Pause');
    $u30 = $mkUnit('Page');
    // wait_minutes parks the participant on the Pause instead of elapsing it.
    $db->exec("INSERT INTO survey_pauses (id, wait_minutes, body, body_parsed) VALUES (:id, 1440, 'p10', '<p>p10</p>')", array('id' => $u10));
    $db->exec("INSERT INTO survey_pauses (id, wait_minutes, body, body_parsed) VALUES (:id, 1440, 'p20', '<p>p20</p>')", array('id' => $u20));
    $db->exec("INSERT INTO survey_pages (id, title, body, body_parsed) VALUES (:id, 't', 'end', '<p>end</p>')", array('id' => $u30));

    $mkPlacement = function (int $unit_id, int $pos) use ($db, $run_id) {
        $db->exec("INSERT INTO survey_run_units (run_id, unit_id, position) VALUES (:rid, :uid, :pos)",
            array('rid' => $run_id, 'uid' => $unit_id, 'pos' => $pos));
        return (int) $db->lastInsertId();
    };
    $ru10 = $mkPlacement($u10, 10);
    $mkPlacement($u20, 20);
    $mkPlacement($u30, 30);

    // ── participant ──
    $db->exec("INSERT INTO survey_users (user_code, created) VALUES (:c, NOW())", array('c' => $participant));
    $participant_id = (int) $db->lastInsertId();
    $user = new User($participant_id, $participant);

    // ── enrol + park at pos 10 ──
    $rs = new RunSession($participant, new Run(null, $run_id), array('user' => $user));
    $rs->create($participant);
    $rs->execute();
    $parked = $db->execute("SELECT position FROM survey_run_sessions WHERE session = :s", array('s' => $participant), true);
    ok((int) $parked === 10, "participant parked at pos 10 (got " . var_export($parked, true) . ")");

    // ── the direct invariant runTo() depends on ──
    $placedUnit = RunUnitFactory::make(new Run(null, $run_id), array('id' => $u20));
    ok($placedUnit->valid === true, "a placed unit hydrates valid=true (runTo gate)");

    // ── delete pos-10 unit under the participant, via the editor path ──
    $unit = RunUnit::findByRunUnitId($ru10, array('ignore_missing' => true), new Run(null, $run_id));
    $unit->removeFromRun(null);
    $gone = (int) $db->execute("SELECT COUNT(*) FROM survey_run_units WHERE id = :id", array('id' => $ru10), true);
    ok($gone === 0, "pos-10 placement removed");

    // ── participant reload: must advance to pos 20, not error ──
    Site::getInstance()->alerts = array();
    $threw = null;
    try {
        (new RunSession($participant, new Run(null, $run_id), array('user' => $user)))->execute();
    } catch (\Throwable $e) {
        $threw = get_class($e) . ': ' . $e->getMessage();
    }
    $afterReload = (int) $db->execute("SELECT position FROM survey_run_sessions WHERE session = :s", array('s' => $participant), true);
    ok($threw === null, "participant reload did not throw (" . var_export($threw, true) . ")");
    ok($afterReload === 20, "participant moved on to pos 20 (got {$afterReload})");

    // ── admin push to pos 20: must succeed, no "could not create" alert ──
    Site::getInstance()->alerts = array();
    $pushThrew = null; $pushRet = null;
    try {
        $pushRet = (new RunSession($participant, new Run(null, $run_id), array('user' => $user)))->forceTo(20);
    } catch (\Throwable $e) {
        $pushThrew = get_class($e) . ': ' . $e->getMessage();
    }
    $pushAlerts = alertsText();
    $afterPush = (int) $db->execute("SELECT position FROM survey_run_sessions WHERE session = :s", array('s' => $participant), true);
    ok($pushThrew === null, "admin push did not throw (" . var_export($pushThrew, true) . ")");
    ok(is_array($pushRet), "forceTo(20) returned a body, not false");
    ok(strpos($pushAlerts, 'Could not create unit session') === false, "no 'could not create unit session' alert (got: {$pushAlerts})");
    ok($afterPush === 20, "run session sits at pos 20 after push (got {$afterPush})");
} finally {
    // ── teardown: remove everything this smoke created ──
    if ($run_id) {
        $db->exec("DELETE us FROM survey_unit_sessions us JOIN survey_run_sessions rs ON rs.id = us.run_session_id WHERE rs.run_id = :rid", array('rid' => $run_id));
        $db->exec("DELETE FROM survey_run_sessions WHERE run_id = :rid", array('rid' => $run_id));
        $unit_ids = $db->execute("SELECT unit_id FROM survey_run_units WHERE run_id = :rid AND unit_id IS NOT NULL", array('rid' => $run_id));
        $db->exec("DELETE FROM survey_run_units WHERE run_id = :rid", array('rid' => $run_id));
        foreach (($unit_ids ?: array()) as $r) {
            $uid = (int) $r['unit_id'];
            $db->exec("DELETE FROM survey_pauses WHERE id = :id", array('id' => $uid));
            $db->exec("DELETE FROM survey_pages WHERE id = :id", array('id' => $uid));
            $db->exec("DELETE FROM survey_units WHERE id = :id", array('id' => $uid));
        }
        $db->exec("DELETE FROM survey_runs WHERE id = :rid", array('rid' => $run_id));
    }
    if ($participant_id) {
        $db->exec("DELETE FROM survey_users WHERE id = :id", array('id' => $participant_id));
    }
}

echo $failures ? "\n\e[31m{$failures} check(s) failed\e[0m\n" : "\n\e[32mall checks passed\e[0m\n";
exit($failures ? 1 : 0);
