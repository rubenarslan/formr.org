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
           rs.position, sru.id AS resolved_run_unit_id, sru.unit_id AS placed_unit_id,
           COALESCE(pc.n_placements, 0) AS n_placements
    FROM survey_unit_sessions us
    JOIN survey_run_sessions rs ON rs.id = us.run_session_id
    LEFT JOIN survey_run_units sru ON sru.run_id = rs.run_id AND sru.position = rs.position
    LEFT JOIN (
        SELECT run_id, unit_id, COUNT(*) AS n_placements
        FROM survey_run_units GROUP BY run_id, unit_id
    ) pc ON pc.run_id = rs.run_id AND pc.unit_id = us.unit_id
    WHERE us.run_unit_id IS NULL
      AND us.ended IS NULL AND us.expired IS NULL
", array(), false, false);

$fixed = 0;
$skipped = array();
foreach ($candidates as $row) {
    // Review 2026-07 (item 18c): stamping is only unambiguous when the unit
    // occupies exactly ONE position in the run. For a multi-position unit
    // (the very case patch 048 left NULL), a stale live row from a
    // DIFFERENT placement would get stamped with the current position's id
    // — manufacturing the cross-placement adoption this remediation exists
    // to end. Those stay NULL for manual review.
    $resolvable = $row['resolved_run_unit_id'] !== null
        && (int) $row['placed_unit_id'] === (int) $row['unit_id']
        && (int) $row['n_placements'] === 1;
    if (!$resolvable) {
        $skipped[] = $row;
        continue;
    }
    if (!$dry_run) {
        try {
            $db->exec(
                "UPDATE `survey_unit_sessions` SET `run_unit_id` = :run_unit_id
                 WHERE `id` = :id AND `run_unit_id` IS NULL",
                array('run_unit_id' => (int) $row['resolved_run_unit_id'], 'id' => (int) $row['unit_session_id'])
            );
        } catch (Exception $e) {
            // e.g. a 23000 against patch 063's UNIQUE key when two race-
            // duplicate rows share an iteration — report and continue, never
            // abort the backfill mid-run with prior rows already stamped.
            $row['error'] = $e->getMessage();
            $skipped[] = $row;
            continue;
        }
    }
    $fixed++;
}

echo ($dry_run ? "[dry-run] would backfill" : "Backfilled") . " run_unit_id on {$fixed} alive session(s).\n";
if ($skipped) {
    echo count($skipped) . " alive session(s) left NULL (review manually):\n";
    foreach ($skipped as $row) {
        $why = isset($row['error']) ? "write failed: {$row['error']}"
            : ((int) $row['n_placements'] > 1 ? "unit has {$row['n_placements']} placements in the run (ambiguous)"
                                              : "current position does not host their unit");
        echo "  unit_session {$row['unit_session_id']} (unit {$row['unit_id']}, run_session {$row['run_session_id']}, position " . var_export($row['position'], true) . ") — {$why}\n";
    }
}
exit(0);
