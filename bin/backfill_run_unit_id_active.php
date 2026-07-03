#!/usr/bin/php
<?php
/**
 * One-shot remediation for the D1 reused-unit fixes (v0.26.4).
 *
 * Patch 048 deliberately left `survey_unit_sessions.run_unit_id` NULL for
 * units placed at multiple positions (their historical placement is
 * ambiguous). For CLOSED sessions that is fine — but still-ALIVE legacy
 * sessions keep hitting the `unit_id` fallback in the fixed lookups, so
 * the cross-placement adoption/supersede exposure persists for exactly
 * the in-flight participants who were affected in production.
 *
 * This script closes that gap conservatively: for each alive session
 * (ended IS NULL AND expired IS NULL) with run_unit_id NULL, it resolves
 * the placement from its run session's CURRENT position — but only when
 * that position's survey_run_units row exists AND hosts the session's
 * own unit_id (i.e. the participant is demonstrably sitting on that
 * placement right now). Anything else is left NULL and reported for
 * manual review.
 *
 * Idempotent; re-runs are no-ops. Usage:
 *   php bin/backfill_run_unit_id_active.php [--dry-run]
 */
require_once dirname(__FILE__) . '/../setup.php';

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line\n");
}

$dry_run = in_array('--dry-run', $argv, true);
$db = DB::getInstance();

$candidates = $db->execute("
    SELECT us.id AS unit_session_id, us.unit_id, rs.id AS run_session_id,
           rs.position, sru.id AS resolved_run_unit_id, sru.unit_id AS placed_unit_id
    FROM survey_unit_sessions us
    JOIN survey_run_sessions rs ON rs.id = us.run_session_id
    LEFT JOIN survey_run_units sru ON sru.run_id = rs.run_id AND sru.position = rs.position
    WHERE us.run_unit_id IS NULL
      AND us.ended IS NULL AND us.expired IS NULL
", array(), false, false);

$fixed = 0;
$skipped = array();
foreach ($candidates as $row) {
    $resolvable = $row['resolved_run_unit_id'] !== null
        && (int) $row['placed_unit_id'] === (int) $row['unit_id'];
    if (!$resolvable) {
        $skipped[] = $row;
        continue;
    }
    if (!$dry_run) {
        $db->exec(
            "UPDATE `survey_unit_sessions` SET `run_unit_id` = :run_unit_id
             WHERE `id` = :id AND `run_unit_id` IS NULL",
            array('run_unit_id' => (int) $row['resolved_run_unit_id'], 'id' => (int) $row['unit_session_id'])
        );
    }
    $fixed++;
}

echo ($dry_run ? "[dry-run] would backfill" : "Backfilled") . " run_unit_id on {$fixed} alive session(s).\n";
if ($skipped) {
    echo count($skipped) . " alive session(s) left NULL (current position does not host their unit — review manually):\n";
    foreach ($skipped as $row) {
        echo "  unit_session {$row['unit_session_id']} (unit {$row['unit_id']}, run_session {$row['run_session_id']}, position " . var_export($row['position'], true) . ")\n";
    }
}
exit(0);
