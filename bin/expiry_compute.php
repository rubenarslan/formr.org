#!/usr/bin/php
<?php
/**
 * Diagnostic helper for the e2e wiki-cell matrix.
 *
 * Computes RunUnit::getUnitSessionExpirationData() against the current
 * DB state for a given unit-session, WITHOUT invoking the queue's
 * end()/expire() logic. Lets tests assert "what does the algorithm say
 * the deadline IS RIGHT NOW", independent of the queue daemon's stored
 * `expires` gate.
 *
 * Usage:  php bin/expiry_compute.php --unit-session-id=NNN
 *
 * Output: one JSON line: {expires, expires_unix, expired, queued,
 *                         end_session, ago_minutes, ahead_minutes}
 *
 * `expires_unix` is the algorithm's deadline as a unix epoch (or null).
 * `ago_minutes` / `ahead_minutes` are the relative distance from NOW
 * (one will be set, the other null) to make assertions read clearly.
 */
require_once __DIR__ . '/../setup.php';

$opts = getopt('', ['unit-session-id:']);
if (empty($opts['unit-session-id'])) {
    fwrite(STDERR, "missing --unit-session-id\n");
    exit(1);
}
$us_id = (int)$opts['unit-session-id'];

$db = DB::getInstance();
$row = $db->findRow('survey_unit_sessions', ['id' => $us_id]);
if (!$row) {
    fwrite(STDERR, "unit-session $us_id not found\n");
    exit(1);
}

$rs = new RunSession(null, new Run(null, (int)$db->execute(
    "SELECT run_id FROM survey_run_sessions WHERE id = :id",
    ['id' => $row['run_session_id']], true
)), ['id' => $row['run_session_id']]);

$us = new UnitSession($rs, null, ['id' => $us_id, 'load' => true]);

$data = $us->runUnit->getUnitSessionExpirationData($us);

$now = time();
$expires_unix = !empty($data['expires']) ? (int)$data['expires'] : null;

$out = [
    'expires_raw'   => $data['expires']   ?? null,   // what the calc actually returned
    'expires_unix'  => $expires_unix,                // normalised epoch
    'expired'       => !empty($data['expired']),
    'queued'        => $data['queued']    ?? null,
    'end_session'   => !empty($data['end_session']),
    'check_failed'  => !empty($data['check_failed']),
];
if ($expires_unix !== null) {
    $delta = $expires_unix - $now;
    if ($delta < 0) {
        $out['ago_minutes']   = round(-$delta / 60, 2);
        $out['ahead_minutes'] = null;
    } else {
        $out['ago_minutes']   = null;
        $out['ahead_minutes'] = round($delta / 60, 2);
    }
} else {
    $out['ago_minutes']   = null;
    $out['ahead_minutes'] = null;
}

echo json_encode($out) . "\n";
