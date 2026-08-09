#!/usr/bin/php
<?php
/**
 * Wait `expired`-leak probe.
 *
 * Complements bin/test_wait_expiry_probe.php, which only ever drives
 * scenarios with age = 0 — i.e. it never lets the wait actually elapse,
 * so it never exercises the branch that can call expire() at all.
 *
 * This probe asks a different question: WHEN getUnitSessionExpirationData()
 * reports expired = true, what does the rest of the chain do with it?
 *
 * Claim under test:
 *   UnitSession::isExpired() merges the whole $expirationData into
 *   $execResults (UnitSession.php:198) BEFORE returning false via the
 *   end_session branch (:211). So execResults keeps expired = true.
 *   execute() then returns early on end_session (:176) WITHOUT ever
 *   calling Wait::getUnitSessionOutput(). RunSession::executeUnitSession()
 *   tests !empty($result['expired']) FIRST (:370) and calls expire(),
 *   which writes result = 'expired' and leaves `expires` untouched.
 *
 * If true, the observed production signature (result = 'expired',
 * advanced to the next position, `expires` column still correct) is
 * produced by the ordinary elapse path, and the Wait's own three-way
 * branch is never consulted.
 *
 * Usage:
 *   docker exec formr_app php /formr/bin/test_wait_expired_leak_probe.php <run_id> <unit_id>
 *   docker exec formr_app php /formr/bin/test_wait_expired_leak_probe.php 43 707
 */
require_once dirname(__FILE__) . '/../setup.php';

$run_id  = (int) ($argv[1] ?? 43);
$unit_id = (int) ($argv[2] ?? 707);

$db = DB::getInstance();

function peek($obj, string $prop) {
    $r = new ReflectionObject($obj);
    while ($r && !$r->hasProperty($prop)) { $r = $r->getParentClass(); }
    if (!$r) { return '<none>'; }
    $p = $r->getProperty($prop); $p->setAccessible(true);
    return $p->getValue($obj);
}

/** A Wait that records whether getUnitSessionOutput() was reached. */
class ProbeWait extends Wait {
    public $outputCalled = false;
    public $outputReturned = null;
    /** Simulate Pause::__construct's findRow('survey_pauses') coming back empty. */
    public $blankPauseRow = false;
    public function getUnitSessionOutput(UnitSession $unitSession) {
        $this->outputCalled = true;
        return $this->outputReturned = parent::getUnitSessionOutput($unitSession);
    }
    public function blankPauseRow() {
        $this->blankPauseRow = true;
        $this->wait_minutes = $this->relative_to = $this->wait_until_time = $this->wait_until_date = null;
        return $this;
    }
}

$run = new Run(null, $run_id);
if (!$run->valid) { fwrite(STDERR, "run {$run_id} not valid\n"); exit(1); }

$probe_rs = [];

/**
 * @param int   $age     backdate the unit session `created` by N seconds
 * @param mixed $expires value to pre-store in survey_unit_sessions.expires
 * @param bool  $cron    drive as the queue daemon rather than a web request
 */
function scenario(string $label, int $run_id, int $unit_id, int $age, $expires, bool $cron, $blank = false) {
    global $db, $run, $probe_rs;

    // survey_run_sessions has UNIQUE KEY run_user (user_id, run_id), so
    // each scenario needs its own throwaway participant.
    $key = 'PROBE' . bin2hex(random_bytes(26));
    $db->insert('survey_users', ['user_code' => $key, 'created' => mysql_datetime(time())]);
    $user_id = $db->lastInsertId();

    $db->insert('survey_run_sessions', [
        'run_id' => $run_id, 'session' => $key, 'user_id' => $user_id,
        'position' => 50, 'created' => mysql_datetime(time()),
    ]);
    $rs_id = $db->lastInsertId();
    $probe_rs[] = [$rs_id, $user_id];

    $row = [
        'run_session_id' => $rs_id,
        'unit_id'        => $unit_id,
        'created'        => mysql_datetime(time() - $age),
    ];
    if ($expires !== null) { $row['expires'] = $expires; $row['queued'] = 2; }
    $db->insert('survey_unit_sessions', $row);
    $us_id = $db->lastInsertId();

    $runSession = new RunSession($key, $run);
    $runSession->user->cron = $cron;
    $runUnit = new ProbeWait($run, ['id' => $unit_id]);
    if ($blank === true) { $runUnit->blankPauseRow(); }
    if ($blank === 'gcus') {
        // Hydrate exactly the way RunSession::getCurrentUnitSession():534
        // does: hand the constructor a full DB row, with no 'load' key.
        $unitSession = new UnitSession($runSession, $runUnit, $db->findRow(
            'survey_unit_sessions', ['id' => $us_id],
            'id, unit_id, run_session_id, created, expires, ended, expired'
        ));
    } else {
        $unitSession = new UnitSession($runSession, $runUnit, ['id' => $us_id, 'load' => true]);
    }
    printf("%-42s hydrated: created=%s expires=%s\n", '', var_export($unitSession->created, true),
        var_export($unitSession->expires, true));

    $expires_before = $db->findValue('survey_unit_sessions', ['id' => $us_id], 'expires');

    $res = $unitSession->execute();

    // Replay what RunSession::executeUnitSession() would do with $res,
    // in its real order (expired -> end_session -> queue).
    $verdict = 'other';
    if (!empty($res['expired']))          { $unitSession->expire(); $verdict = 'expire()'; }
    elseif (!empty($res['end_session']))  { $unitSession->end();    $verdict = 'end()'; }
    elseif (isset($res['queue']))         { $verdict = 'queue()'; }

    $branch = isset($res['run_to']) ? 'run_to = ' . var_export($res['run_to'], true)
            : (!empty($res['move_on'])   ? 'move_on (next position)'
            : (!empty($res['wait_user']) ? 'wait_user (stay put)' : 'none'));

    $after = $db->findRow('survey_unit_sessions', ['id' => $us_id], 'result, expires, expired, ended');

    printf("%-42s age=%-6s cron=%-5s\n", $label, $age . 's', var_export($cron, true));
    printf("    execResults: expired=%-5s end_session=%-5s -> %s via %s\n",
        var_export($res['expired'] ?? null, true),
        var_export($res['end_session'] ?? null, true), $branch, $verdict);
    printf("    Wait::getUnitSessionOutput() called? %s%s\e[0m\n",
        $runUnit->outputCalled ? '' : "\e[31m", $runUnit->outputCalled ? 'yes' : 'NO - branch never consulted');
    printf("    anchor: relative_to=%s resolved=%s | check_failed=%s computed_expires=%s\n",
        var_export(peek($runUnit, 'relative_to'), true),
        var_export(peek($runUnit, 'relative_to_result'), true),
        var_export($res['check_failed'] ?? null, true),
        isset($res['expires']) ? var_export(mysql_datetime($res['expires']), true) : 'unset');
    printf("    DB result=%-12s expires %s -> %s\n\n",
        var_export($after['result'], true),
        var_export($expires_before, true), var_export($after['expires'], true));

    return $after['result'];
}

$wm   = (float) $db->findValue('survey_pauses', ['id' => $unit_id], 'wait_minutes');
$secs = (int) ($wm * 60);
echo "== Wait unit {$unit_id}, wait_minutes = {$wm} (= {$secs}s) ==\n\n";

// Elapsed cases: `created` backdated past the deadline, `expires` stored
// as the CORRECT value the parking pass would have written.
$correct_expires = static fn(int $age) => mysql_datetime(time() - $age + $secs);

scenario('G elapsed, correct stored expires, web',  $run_id, $unit_id, $secs + 30, $correct_expires($secs + 30), false);
scenario('H elapsed, correct stored expires, cron', $run_id, $unit_id, $secs + 30, $correct_expires($secs + 30), true);
scenario('I elapsed, no stored expires, web',       $run_id, $unit_id, $secs + 30, null, false);
// Control: not elapsed, participant returns early. Should take run_to.
scenario('J not elapsed, participant returns, web', $run_id, $unit_id, 5, $correct_expires(5), false);

// Fail-open: survey_pauses row did not load, so $conditions ends up empty
// and Pause.php:247 declares the wait over. Note this path sets NO
// 'expires' key, so the DB column keeps the correct parked value.
scenario('K blank pauses row, stored expires, web',  $run_id, $unit_id, 1, $correct_expires(1), false, true);
scenario('L blank pauses row, no stored expires',    $run_id, $unit_id, 1, null, false, true);

// The real web path: RunSession::getCurrentUnitSession() hands the
// constructor a full DB row, but the $options allowlist keeps only
// 'id' and no 'load' key is present, so load() never runs. created and
// expires arrive NULL even though the DB row has correct values.
scenario('M participant returns, hydrated as web',   $run_id, $unit_id, 1, $correct_expires(1), false, 'gcus');
scenario('N participant returns 13s in, as web',     $run_id, $unit_id, 13, $correct_expires(13), false, 'gcus');

foreach ($probe_rs as [$rs_id, $user_id]) {
    $db->exec('DELETE FROM survey_unit_sessions WHERE run_session_id = :id', ['id' => $rs_id]);
    $db->exec('DELETE FROM survey_run_sessions WHERE id = :id', ['id' => $rs_id]);
    $db->exec('DELETE FROM survey_users WHERE id = :id', ['id' => $user_id]);
}
echo "(cleaned up " . count($probe_rs) . " probe run sessions)\n";
