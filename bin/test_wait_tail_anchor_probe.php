#!/usr/bin/php
<?php
/**
 * Wait stale-anchor probe — the root cause behind the premature-expiry bug.
 *
 * Two defects compound:
 *
 *  (1) RunSession::getCurrentUnitSession():534 hands `new UnitSession()` a
 *      full DB row, but the constructor's $options allowlist (ed56a95f)
 *      keeps only 'id', and the row carries no 'load' key, so load() never
 *      runs. Every participant web request at a parked Wait therefore has
 *      created = NULL and expires = NULL.
 *
 *      -> the fast path at Pause.php:120 (stored expires still in future)
 *         cannot fire, and the fast path at Pause.php:128 (anchor =
 *         $unitSession->created) cannot fire either. The anchor is resolved
 *         through OpenCPU as tail(survey_unit_sessions$created, 1).
 *
 *  (2) The query backing that data frame (UnitSession.php:759-774) has NO
 *      ORDER BY. MariaDB returns rows grouped by unit_id, so tail(...,1) is
 *      not "the most recent unit session" — it is the newest session of
 *      whichever unit_id sorts last. On run 43 that is unit 713, the
 *      SkipBackward at position 110, which is only reached via the
 *      position-100 branch. A participant looping through 80/90 leaves it
 *      to go stale while they keep cycling.
 *
 * When that stale anchor is older than wait_minutes, the Wait is declared
 * expired the instant the participant returns. expire() never writes
 * `expires`, so the column keeps the correct value the parking pass wrote
 * — which is why the production evidence looked like a correct deadline
 * being ignored rather than a wrong one being computed.
 *
 * Usage:
 *   docker exec formr_app php /formr/bin/test_wait_tail_anchor_probe.php
 */
require_once dirname(__FILE__) . '/../setup.php';

$run_id  = (int) ($argv[1] ?? 43);
$unit_id = (int) ($argv[2] ?? 707);   // Wait at position 50, wait_minutes = 10
$tail_unit = (int) ($argv[3] ?? 713); // SkipBackward at position 110

$db  = DB::getInstance();
$run = new Run(null, $run_id);
if (!$run->valid) { fwrite(STDERR, "run {$run_id} not valid\n"); exit(1); }

$wm   = (float) $db->findValue('survey_pauses', ['id' => $unit_id], 'wait_minutes');
$secs = (int) ($wm * 60);

$cleanup = [];

/**
 * @param int $tail_age how many seconds ago the highest-unit_id session was created
 */
$failures = 0;

/**
 * @param string $expect 'run_to' (participant returned early) or 'move_on'
 */
function scenario(string $label, int $run_id, int $unit_id, int $tail_unit, int $tail_age, int $secs, bool $hydrate = false, string $expect = 'run_to', int $wait_age = 1) {
    global $db, $run, $cleanup, $failures;

    $key = 'TAIL' . bin2hex(random_bytes(26));
    $db->insert('survey_users', ['user_code' => $key, 'created' => mysql_datetime(time())]);
    $user_id = $db->lastInsertId();
    $db->insert('survey_run_sessions', [
        'run_id' => $run_id, 'session' => $key, 'user_id' => $user_id,
        'position' => 50, 'created' => mysql_datetime(time() - 1800),
    ]);
    $rs_id = $db->lastInsertId();
    $cleanup[] = [$rs_id, $user_id];

    // A realistic multi-cycle history, shaped like a real ESM session so the
    // optimizer picks the same plan (drive survey_run_units -> per-unit ref
    // lookup into survey_unit_sessions, i.e. output grouped by unit).
    // Units 703/704/705 are hit every cycle; 712/713 only on the
    // self-init branch, so they lag behind.
    $insert = function (int $uid, int $age) use ($db, $rs_id) {
        $db->insert('survey_unit_sessions', [
            'run_session_id' => $rs_id, 'unit_id' => $uid,
            'created' => mysql_datetime(time() - $age), 'ended' => mysql_datetime(time() - $age + 1),
        ]);
    };
    foreach ([1500, 1200, 900, 600, 300, 120, 60, 20] as $age) {
        $insert(703, $age); $insert(704, $age - 1); $insert(705, $age - 2);
    }
    foreach ([1450, 1150, 550, 250, 90] as $age) { $insert(706, $age); $insert(710, $age - 1); $insert(711, $age - 2); }
    // The lagging branch: last visited $tail_age seconds ago.
    $insert(712, $tail_age + 1);
    $insert($tail_unit, $tail_age);

    // The Wait the participant is parked at, parked by the cascade with the
    // CORRECT expires (created + wait_minutes).
    $created = time() - $wait_age;
    $db->insert('survey_unit_sessions', [
        'run_session_id' => $rs_id, 'unit_id' => $unit_id,
        'created' => mysql_datetime($created),
        'expires' => mysql_datetime($created + $secs), 'queued' => 2,
    ]);
    $us_id = $db->lastInsertId();

    $runSession = new RunSession($key, $run);
    // The participant returning is a web request, not the daemon. In console
    // User::isCron() defaults true, which would mask the run_to branch.
    $runSession->user->cron = false;
    $runUnit = RunUnitFactory::make($run, ['id' => $unit_id]);

    if ($hydrate) {
        // The proposed fix: make getCurrentUnitSession() actually load().
        $unitSession = new UnitSession($runSession, $runUnit, ['id' => $us_id, 'load' => true]);
    } else {
        // Hydrate exactly as RunSession::getCurrentUnitSession():534 does.
        $row = $db->findRow('survey_unit_sessions', ['id' => $us_id],
            'id, unit_id, run_session_id, created, expires, ended, expired');
        $unitSession = new UnitSession($runSession, $runUnit, $row);
    }

    $res = $unitSession->execute();
    $verdict = 'other';
    if (!empty($res['expired']))         { $unitSession->expire(); $verdict = 'expire()'; }
    elseif (!empty($res['end_session'])) { $unitSession->end();    $verdict = 'end()'; }

    $r = new ReflectionObject($runUnit);
    while ($r && !$r->hasProperty('relative_to_result')) { $r = $r->getParentClass(); }
    $p = $r->getProperty('relative_to_result'); $p->setAccessible(true);
    $anchor = $p->getValue($runUnit);

    $after = $db->findRow('survey_unit_sessions', ['id' => $us_id], 'result, expires');
    $branch = isset($res['run_to']) ? 'run_to = ' . var_export($res['run_to'], true)
            : (!empty($res['move_on']) ? 'move_on -> next position (Email at 60)' : 'none');

    $got = isset($res['run_to']) ? 'run_to' : (!empty($res['move_on']) ? 'move_on' : 'none');
    $ok  = ($got === $expect);
    if (!$ok) { $failures++; }

    printf("%s %s\n", $ok ? "\e[32mPASS\e[0m" : "\e[31mFAIL\e[0m", $label);
    printf("    lagging unit last seen %ds ago | Wait entered %ds ago, deadline %ds\n", $tail_age, $wait_age, $secs);
    printf("    tail() anchor resolved to: %s\n", var_export($anchor, true));
    printf("    expected %s, got %s (%s) via %s | DB result=%s expires=%s\n\n",
        $expect, $got, $branch, $verdict, var_export($after['result'], true), var_export($after['expires'], true));
}

echo "== Wait unit {$unit_id}, wait_minutes = {$wm} ({$secs}s); tail unit = {$tail_unit} ==\n\n";

// A participant returning 1 second into a 10-minute Wait must always take
// run_to = body, whatever the rest of their history looks like.
scenario("A lagging unit last seen 29s ago",        $run_id, $unit_id, $tail_unit, 29, $secs);
// B is the regression case: pre-fix this returned move_on -> Email at 60.
// It hydrates the way getCurrentUnitSession() used to, so it stays honest
// about the ORDER BY fix even once the load() fix is in place.
scenario("B lagging unit stale (20 min ago)",       $run_id, $unit_id, $tail_unit, 1200, $secs);
scenario("C stale history, load()ed unit session", $run_id, $unit_id, $tail_unit, 1200, $secs, true);
// D a genuinely elapsed Wait must still move on.
scenario("D genuine elapse still moves on",         $run_id, $unit_id, $tail_unit, 29, $secs, true, 'move_on', $secs + 60);

foreach ($cleanup as [$rs_id, $user_id]) {
    $db->exec('DELETE FROM survey_unit_sessions WHERE run_session_id = :id', ['id' => $rs_id]);
    $db->exec('DELETE FROM survey_run_sessions WHERE id = :id', ['id' => $rs_id]);
    $db->exec('DELETE FROM survey_users WHERE id = :id', ['id' => $user_id]);
}
echo "(cleaned up " . count($cleanup) . " probe run sessions)\n";
echo $failures === 0 ? "\e[32mALL SCENARIOS PASS\e[0m\n" : "\e[31m{$failures} SCENARIO(S) FAILED\e[0m\n";
exit($failures === 0 ? 0 : 1);
