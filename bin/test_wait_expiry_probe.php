#!/usr/bin/php
<?php
/**
 * Wait-unit expiry probe. Investigates why a Wait with wait_minutes = N
 * can report `expired` within a second of the unit session being created
 * (observed on an ESM run: a 10-minute Wait at position 50 expiring in
 * 0-1 s, which then walks the run into the Email reminder at position 60).
 *
 * Drives UnitSession::execute() through the scenarios the real cascade
 * produces and reports which branch each one takes.
 *
 * Usage:
 *   docker exec formr_app php bin/test_wait_expiry_probe.php <run_id> <unit_id>
 *   docker exec formr_app php bin/test_wait_expiry_probe.php 43 707
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

$run = new Run(null, $run_id);
if (!$run->valid) { fwrite(STDERR, "run {$run_id} not valid\n"); exit(1); }

$probe_rs = [];

/**
 * @param int    $age      backdate the unit session `created` by N seconds
 * @param mixed  $expires  value to pre-store in survey_unit_sessions.expires
 * @param bool   $cron     run as the queue daemon rather than a web request
 */
function scenario(string $label, int $run_id, int $unit_id, int $age, $expires, bool $cron) {
    global $db, $run, $probe_rs;

    // survey_run_sessions has UNIQUE KEY run_user (user_id, run_id), so each
    // scenario needs its own throwaway participant.
    $key = 'PROBE' . bin2hex(random_bytes(26));
    $db->insert('survey_users', [
        'user_code' => $key,
        'created'   => mysql_datetime(time()),
    ]);
    $user_id = $db->lastInsertId();

    $db->insert('survey_run_sessions', [
        'run_id' => $run_id, 'session' => $key, 'user_id' => $user_id,
        'position' => 50, 'created' => mysql_datetime(time()),
    ]);
    $rs_id = $db->lastInsertId();
    $probe_rs[] = [$rs_id, $user_id];

    $created = mysql_datetime(time() - $age);
    $row = ['run_session_id' => $rs_id, 'unit_id' => $unit_id, 'created' => $created];
    if ($expires !== null) { $row['expires'] = $expires; $row['queued'] = 2; }
    $db->insert('survey_unit_sessions', $row);
    $us_id = $db->lastInsertId();

    $runSession = new RunSession($key, $run);
    $runSession->user->cron = $cron;
    $runUnit = RunUnitFactory::make($run, ['id' => $unit_id]);
    $unitSession = new UnitSession($runSession, $runUnit, ['id' => $us_id, 'load' => true]);

    try {
        $res = $unitSession->execute();
    } catch (Throwable $e) {
        echo "{$label}: THREW " . get_class($e) . ': ' . $e->getMessage() . "\n";
        echo '  at ' . $e->getFile() . ':' . $e->getLine() . "\n\n";
        return false;
    }

    $branch = !empty($res['expired'])            ? "\e[31mEXPIRED -> move_on (next position)\e[0m"
            : (isset($res['run_to'])             ? "run_to = " . var_export($res['run_to'], true)
            : (!empty($res['wait_user'])         ? 'wait_user (stay put)'
            : (!empty($res['end_session'])       ? 'end_session' : 'other')));

    $exp = peek($runUnit, 'relative_to_result');
    printf("%-46s age=%-5s stored_expires=%-21s cron=%-5s\n    -> %s\n",
        $label, $age . 's', var_export($expires, true), var_export($cron, true), $branch);
    printf("       relative_to=%s  resolved=%s  wait_minutes=%s\n\n",
        var_export(peek($runUnit, 'relative_to'), true), var_export($exp, true),
        var_export(peek($runUnit, 'wait_minutes'), true));

    return !empty($res['expired']);
}

$wm = (float) $db->findValue('survey_pauses', ['id' => $unit_id], 'wait_minutes');
echo "== Wait unit {$unit_id}, wait_minutes = {$wm} (= " . ($wm * 60) . "s) ==\n\n";

$future = mysql_datetime(time() + (int) ($wm * 60));
$past   = mysql_datetime(time() - 10);

$bad = 0;
$bad += scenario('A fresh, no stored expires, web',   $run_id, $unit_id, 0, null,    false);
$bad += scenario('B fresh, no stored expires, cron',  $run_id, $unit_id, 0, null,    true);
$bad += scenario('C stored expires in future, web',   $run_id, $unit_id, 0, $future, false);
$bad += scenario('D stored expires in future, cron',  $run_id, $unit_id, 0, $future, true);
$bad += scenario('E stored expires in PAST, web',     $run_id, $unit_id, 0, $past,   false);
$bad += scenario('F stored expires in PAST, cron',    $run_id, $unit_id, 0, $past,   true);

echo "Scenarios reporting EXPIRED while the wait had not elapsed: {$bad}\n";

foreach ($probe_rs as [$rs_id, $user_id]) {
    $db->exec('DELETE FROM survey_unit_sessions WHERE run_session_id = :id', ['id' => $rs_id]);
    $db->exec('DELETE FROM survey_run_sessions WHERE id = :id', ['id' => $rs_id]);
    $db->exec('DELETE FROM survey_users WHERE id = :id', ['id' => $user_id]);
}
echo "(cleaned up " . count($probe_rs) . " probe run sessions)\n";
