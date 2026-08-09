#!/usr/bin/php
<?php
/**
 * Reproduces the production cascade that ends with an unexpected Email:
 * an elapsed Wait is picked up by the queue daemon, which ends it and
 * moves on, cascading Wait(30) -> PushMessage(40) -> Wait(50). The
 * question is what state Wait(50) — a 10-minute wait — lands in.
 *
 * Parks a throwaway participant at a given position with an already-past
 * `expires` + queued=2, then runs the real UnitSessionQueue pass over it
 * and dumps every unit session the cascade produced.
 *
 * Usage:
 *   docker exec formr_app php bin/test_wait_cascade_probe.php [run_id] [start_position]
 *   docker exec formr_app php bin/test_wait_cascade_probe.php 43 30
 */
require_once dirname(__FILE__) . '/../setup.php';

$run_id   = (int) ($argv[1] ?? 43);
$start_at = (int) ($argv[2] ?? 30);

$db  = DB::getInstance();
$run = new Run(null, $run_id);
if (!$run->valid) { fwrite(STDERR, "run {$run_id} not valid\n"); exit(1); }

$unit_id = $db->findValue('survey_run_units', ['run_id' => $run_id, 'position' => $start_at], 'unit_id');
if (!$unit_id) { fwrite(STDERR, "no unit at position {$start_at}\n"); exit(1); }

$key = 'CASCADE' . bin2hex(random_bytes(24));
$db->insert('survey_users', ['user_code' => $key, 'created' => mysql_datetime(time())]);
$user_id = $db->lastInsertId();
$db->insert('survey_run_sessions', [
    'run_id' => $run_id, 'session' => $key, 'user_id' => $user_id,
    'position' => $start_at, 'created' => mysql_datetime(time()),
]);
$rs_id = $db->lastInsertId();

// Park the participant on the start unit with an expiry already in the past,
// exactly as the daemon would find it.
$db->insert('survey_unit_sessions', [
    'run_session_id' => $rs_id,
    'unit_id'        => $unit_id,
    'created'        => mysql_datetime(time() - 120),
    'expires'        => mysql_datetime(time() - 60),
    'queued'         => UnitSessionQueue::QUEUED_TO_END,
]);
$us_id = $db->lastInsertId();
$db->update('survey_run_sessions', ['current_unit_session_id' => $us_id], ['id' => $rs_id]);

echo "Parked participant {$key} at position {$start_at} (unit {$unit_id}, session {$us_id}).\n";
echo "Running one UnitSessionQueue pass...\n\n";

$config = array_merge(Config::get('unit_session'), ['queue_type' => 'UnitSession']);
$queue  = new UnitSessionQueue($db, $config);
$queue->runOnce();

$rows = $db->execute(
    'SELECT us.id, ru.position, u.type, us.created, us.expires, us.ended, us.expired,
            us.queued, us.result
       FROM survey_unit_sessions us
       LEFT JOIN survey_units u      ON u.id = us.unit_id
       LEFT JOIN survey_run_units ru ON ru.unit_id = us.unit_id
      WHERE us.run_session_id = :rs
      ORDER BY us.id ASC',
    ['rs' => $rs_id]
);

printf("%-8s %-5s %-13s %-20s %-20s %-20s %-7s %s\n",
    'id', 'pos', 'type', 'created', 'expires', 'ended/expired', 'queued', 'result');
foreach ($rows as $r) {
    $done = $r['ended'] ? $r['ended'] . ' (end)' : ($r['expired'] ? $r['expired'] . ' (EXP)' : '-');
    printf("%-8s %-5s %-13s %-20s %-20s %-20s %-7s %s\n",
        $r['id'], $r['position'], $r['type'], $r['created'],
        $r['expires'] ?: '-', $done, $r['queued'], $r['result'] ?: '-');
}

$pos = $db->findValue('survey_run_sessions', ['id' => $rs_id], 'position');
echo "\nRun session now at position {$pos}.\n";

$emails = 0;
foreach ($rows as $r) { if ($r['type'] === 'Email') { $emails++; } }
echo $emails > 0
    ? "\e[31mEMAIL UNIT REACHED ({$emails}) — reminder fired without the 10-minute wait elapsing.\e[0m\n"
    : "\e[32mNo Email unit reached — cascade parked before the reminder, as designed.\e[0m\n";

echo "\nKeeping rows for inspection. Clean up with:\n";
echo "  DELETE FROM survey_run_sessions WHERE session = '{$key}';\n";
echo "  DELETE FROM survey_users WHERE id = {$user_id};\n";
