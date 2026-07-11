#!/usr/bin/php
<?php
/**
 * Recovery sweep for stalled unit sessions (audit F6, 2026-07).
 *
 * The moveOn cascade is a chain of autocommit writes with no outbox: a
 * daemon SIGKILL or exception between "dequeue current" and "enqueue
 * successor" leaves a live auto-advancing session at queued=0 — a state
 * the daemon's pickup SELECT can never see, so the participant silently
 * drops out of the automated schedule. This sweep finds those rows on
 * cron-active runs and:
 *
 *   - if the row IS the run session's current unit session: re-executes
 *     the run session (under its lock) so the cascade resumes;
 *   - otherwise (stale non-current litter — e.g. legacy reminder rows,
 *     historic fan-out): terminal-stamps it expired so it stops counting
 *     as live in every audit, preserving its result for the trail.
 *
 * External units are deliberately excluded: api_end externals wait for a
 * callback and address externals wait for the participant's browser —
 * both by design (see executeUnitSession's F5 guard).
 *
 * Usage: php bin/sweep_stalled_unit_sessions.php [--dry-run] [--limit=N]
 *        [--min-age-minutes=N]
 * Scheduled hourly via config/formr_crontab. Safe to re-run; bounded by
 * --limit (default 500) per invocation.
 */
require_once dirname(__FILE__) . '/../setup.php';

$opts = getopt('', ['dry-run', 'limit::', 'min-age-minutes::']);
$dry = isset($opts['dry-run']);
$limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 500;
$minAge = isset($opts['min-age-minutes']) ? max(1, (int) $opts['min-age-minutes']) : 30;

$lock = fopen(APPLICATION_ROOT . 'tmp/sweep_stalled_unit_sessions.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "sweep: another instance is running, exiting\n");
    exit(0);
}

$db = DB::getInstance();
$types = "'Pause','Wait','Shuffle','SkipForward','SkipBackward','Email','PushMessage'";

$rows = $db->execute("
    SELECT us.id, us.run_session_id, us.result, rs.session, r.id AS run_id, r.name AS run_name, u.type
    FROM survey_unit_sessions us
    JOIN survey_units u ON u.id = us.unit_id
    JOIN survey_run_sessions rs ON rs.id = us.run_session_id
    JOIN survey_runs r ON r.id = rs.run_id
    WHERE us.ended IS NULL AND us.expired IS NULL AND us.queued = 0
      AND u.type IN ({$types})
      AND us.created < NOW() - INTERVAL :min_age MINUTE
      AND rs.ended IS NULL
      AND r.cron_active = 1
    ORDER BY us.id ASC
    LIMIT {$limit}", ['min_age' => $minAge]);

if (!$rows) {
    fwrite(STDERR, "sweep: nothing to do\n");
    exit(0);
}

$runs = [];
$executed = $stamped = $skipped = 0;

foreach ($rows as $row) {
    if (!isset($runs[$row['run_id']])) {
        $runs[$row['run_id']] = new Run(null, $row['run_id']);
    }
    $run = $runs[$row['run_id']];
    if (!$run->valid) {
        $skipped++;
        continue;
    }

    $runSession = new RunSession($row['session'], $run);
    if (!$runSession->id) {
        $skipped++;
        continue;
    }
    $runSession->user->cron = true;

    $current = $runSession->getCurrentUnitSession();
    if ($current && (int) $current->id === (int) $row['id']) {
        $executed++;
        fwrite(STDERR, "sweep: re-execute run_session {$row['run_session_id']} ({$row['run_name']}, {$row['type']}#{$row['id']})" . ($dry ? ' [dry-run]' : '') . "\n");
        if (!$dry) {
            $runSession->execute();
        }
    } else {
        $stamped++;
        if (!$dry) {
            $db->exec(
                "UPDATE `survey_unit_sessions`
                 SET `expired` = NOW(),
                     `result` = COALESCE(`result`, 'swept_stale'),
                     `state` = :state,
                     `state_log` = COALESCE(`state_log`, :state_log)
                 WHERE `id` = :id AND `ended` IS NULL AND `expired` IS NULL LIMIT 1",
                [
                    'id' => $row['id'],
                    'state' => UnitSessionQueue::STATE_EXPIRED,
                    'state_log' => UnitSession::buildStateLog('swept_stale', [
                        'unit_type' => $row['type'],
                        'msg' => 'stalled non-current session terminal-stamped by sweep_stalled_unit_sessions',
                    ]),
                ]
            );
        }
    }
}

fwrite(STDERR, sprintf(
    "sweep: %d candidates — %d re-executed, %d terminal-stamped, %d skipped%s\n",
    count($rows), $executed, $stamped, $skipped, $dry ? ' (dry-run, nothing written)' : ''
));
exit(0);
