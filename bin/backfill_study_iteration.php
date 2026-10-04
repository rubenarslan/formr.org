#!/usr/bin/php
<?php
/**
 * One-shot backfill: populate survey_unit_sessions.study_iteration and
 * survey_studies.last_iteration from existing per-study wide results
 * tables.
 *
 * After patch 108 adds the columns, this script is the bridge that
 * carries forward the historical iteration values researchers depend
 * on (block-randomization, "Nth participant" analyses). New sessions
 * post-deploy get their study_iteration allocated live by
 * UnitSession::createSurveyStudyRecord — including for any participant
 * who reaches a study between the deploy and this script actually
 * running, so a study can have a MIX of live-allocated (non-NULL) and
 * historical (NULL) study_iteration rows by the time this runs. This
 * script only ever fills the NULL ones; it never touches an
 * already-allocated value.
 *
 * Idempotent: a study with no NULL study_iteration left to backfill
 * (as seen through its wide table) and a last_iteration already caught
 * up is a no-op. Re-runs are safe.
 *
 * Per-study process:
 *   1. Skip (skipped_no_table) if results_table is missing or doesn't
 *      exist on disk.
 *   2. Detect whether the wide table has an `iteration` column
 *      (information_schema.COLUMNS). Pre-2024-07 wide tables predate
 *      that column entirely.
 *   3a. Has `iteration`: UPDATE survey_unit_sessions.study_iteration
 *       via a JOIN with the wide table on session_id, copying
 *       w.iteration — only rows currently NULL
 *       (`WHERE us.study_iteration IS NULL`), so an already-allocated
 *       value (live or a prior backfill run) is never overwritten — and
 *       only where no other session of the study holds that number
 *       (patch 118). A session whose wide number is taken is numbered
 *       above everything on record instead, in its wide row too.
 *   3b. No `iteration` column: there is no historical value to copy,
 *       so NULL study_iteration rows matched to a wide row
 *       (session_id join) are numbered sequentially in wide.created
 *       order, continuing from the current max of already-allocated
 *       study_iteration values for this study (live allocations, or a
 *       prior run) — so newly-assigned historical numbers can never
 *       collide with numbers already handed out.
 *   4. Raise survey_studies.last_iteration to
 *      GREATEST(last_iteration, max iteration now on record for this
 *      study) — always, even for a study with nothing to backfill,
 *      since last_iteration can itself have drifted behind the wide
 *      table. The counter only ever goes UP: a plain SET would risk
 *      rewinding it into values already handed out
 *      (UnitSession::allocateStudyIteration keeps the same invariant
 *      when it self-heals).
 *
 * A study only counts as "skipped, nothing to do" when BOTH (3) found
 * no NULL rows to fill AND (4) found last_iteration already caught up. A
 * study whose transaction fails is rolled back, counted as failed, and the
 * script exits 1.
 *
 * Usage:
 *   docker exec formr_app php bin/backfill_study_iteration.php
 *   docker exec formr_app php bin/backfill_study_iteration.php --dry-run
 *   docker exec formr_app php bin/backfill_study_iteration.php --study=42
 */

require_once dirname(__FILE__) . '/../setup.php';

// Read strictly. getopt() took `--study 42` (with a space) as no study and
// skipped what it did not know, so a mistyped `--dryrun` ran for real.
$dryRun = false;
$onlyStudyId = null;
$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--dry-run') {
        $dryRun = true;
    } elseif (preg_match('/^--study(?:=(.*))?$/', $args[$i], $m)) {
        $value = isset($m[1]) ? $m[1] : (string) ($args[++$i] ?? '');
        if (!ctype_digit($value)) {
            fwrite(STDERR, "--study takes a survey id, not '{$value}'.\n");
            exit(2);
        }
        $onlyStudyId = (int) $value;
    } else {
        fwrite(STDERR, "Unknown argument '{$args[$i]}'. Usage: backfill_study_iteration.php [--dry-run] [--study=ID]\n");
        exit(2);
    }
}

$db = DB::getInstance();

$tag = $dryRun ? '[dry-run]' : '[apply]';
echo "$tag Backfilling study_iteration from wide results tables\n";

// Every study, whatever its `valid` column says: SurveyStudy::createFromFile
// long left that column NULL (it set the flag only after saving), so a
// `valid = 1` filter skipped about half the studies. A study without a
// results table is skipped below.
$select = $db->select('id, name, results_table, last_iteration')
    ->from('survey_studies');
if ($onlyStudyId !== null) {
    $select->where(['id' => $onlyStudyId]);
}
$studies = $select->fetchAll();

$skippedNoTable = 0;
$skippedNothingToDo = 0;
$failed = 0;
$processed = 0;
$totalRowsBackfilled = 0;

foreach ($studies as $study) {
    $studyId = (int) $study['id'];
    $name = $study['name'];
    $resultsTable = $study['results_table'];
    $lastIteration = (int) $study['last_iteration'];

    if (!$resultsTable || !$db->table_exists($resultsTable)) {
        $skippedNoTable++;
        continue;
    }

    // Safe to backtick-interpolate the table name: it was originally
    // generated by SurveyStudy::createResultsTable from a validated
    // study name and lives in survey_studies.results_table, which is
    // not user-editable post-creation. table_exists() above also
    // asserts it matches /^[A-Za-z0-9_]+$/.
    $safeWide = str_replace('`', '``', $resultsTable);

    $hasIterationCol = in_array('iteration', SurveyStudy::wideTableColumns($resultsTable), true);

    $result = $hasIterationCol
        ? backfillFromWideIteration($db, $safeWide, $studyId, $lastIteration, $dryRun)
        : backfillSequentially($db, $safeWide, $studyId, $lastIteration, $dryRun);

    if (!empty($result['failed'])) {
        // Rolled back and reported on STDERR; counted, and the exit status
        // says so (the deploy scripts warn on it).
        $failed++;
        continue;
    }
    $wouldUpdate = $result['rows'];
    $newIteration = $result['newLastIteration'];
    $nothingToDo = ($wouldUpdate === 0 && $newIteration <= $lastIteration);

    if ($nothingToDo) {
        $skippedNothingToDo++;
        continue;
    }

    $label = $dryRun ? 'rows_to_backfill' : 'rows_backfilled';
    $mode = $hasIterationCol ? '' : ' (no iteration column; sequential)';
    if (!empty($result['renumbered'])) {
        $mode .= " ({$result['renumbered']} numbered anew: their wide iteration is held by another session)";
    }
    printf("  study %5d %-40s %s=%d last_iteration %d->%d%s\n",
        $studyId, $name, $label, $wouldUpdate, $lastIteration, $newIteration, $mode);
    $totalRowsBackfilled += $wouldUpdate;
    $processed++;
}

echo "\n$tag Done. processed=$processed skipped_no_table=$skippedNoTable "
    . "skipped_nothing_to_do=$skippedNothingToDo failed=$failed rows_backfilled=$totalRowsBackfilled\n";
exit($failed ? 1 : 0);

/**
 * Has-iteration-column path: copy w.iteration into any NULL
 * us.study_iteration matched by session_id, then raise
 * survey_studies.last_iteration to the wide table's max. Never
 * overwrites a non-NULL study_iteration (WHERE us.study_iteration IS
 * NULL).
 *
 * @return array{rows:int, newLastIteration:int}
 */
function backfillFromWideIteration($db, $safeWide, $studyId, $lastIteration, $dryRun) {
    $studyId = (int) $studyId;
    // The numbers sessions of this study hold (patch 118 keeps them to one
    // session each). A wide iteration among them is not copied: its session
    // is numbered above everything instead, in its wide row too.
    // Wrapped in a derived table, so MariaDB reads it before the UPDATE
    // below changes survey_unit_sessions.
    $taken = "SELECT `t`.`si` FROM (SELECT `study_iteration` AS `si` FROM `survey_unit_sessions`
        WHERE `unit_id` = {$studyId} AND `study_iteration` IS NOT NULL) `t`";
    $counts = $db->query("SELECT COALESCE(SUM(`w`.`iteration` NOT IN ({$taken})), 0) AS `copy`,
            COALESCE(SUM(`w`.`iteration` IN ({$taken})), 0) AS `clash`
        FROM `{$safeWide}` `w`
        INNER JOIN `survey_unit_sessions` `us` ON `us`.`id` = `w`.`session_id`
        WHERE `us`.`study_iteration` IS NULL AND `w`.`iteration` IS NOT NULL");
    $toCopy = isset($counts[0]['copy']) ? (int) $counts[0]['copy'] : 0;
    $clashes = isset($counts[0]['clash']) ? (int) $counts[0]['clash'] : 0;

    $maxRows = $db->query("SELECT COALESCE(MAX(`iteration`), 0) AS max_iter FROM `{$safeWide}`");
    $maxIter = isset($maxRows[0]['max_iter']) ? (int) $maxRows[0]['max_iter'] : 0;
    $newLastIteration = max($lastIteration, $maxIter);

    if ($dryRun) {
        return ['rows' => $toCopy + $clashes, 'renumbered' => $clashes,
            'newLastIteration' => $newLastIteration + $clashes];
    }

    // Run the UPDATE and the last_iteration bump together even when
    // nothing is copied: a study can have nothing left to backfill but
    // still have last_iteration lagging the wide table's max (drift —
    // requirement 4), and that only gets fixed if this transaction
    // actually runs.
    $db->beginTransaction();
    try {
        // DB::query() returns a PDOStatement for non-SELECT (UPDATE here).
        $updateStmt = $db->query("
            UPDATE `survey_unit_sessions` AS `us`
            INNER JOIN `{$safeWide}` AS `w` ON `w`.`session_id` = `us`.`id`
            SET `us`.`study_iteration` = `w`.`iteration`
            WHERE `us`.`study_iteration` IS NULL
              AND `w`.`iteration` IS NOT NULL
              AND `w`.`iteration` NOT IN ({$taken})
        ");
        $rowsUpdated = $updateStmt->rowCount();

        // What is left unnumbered with a wide iteration had theirs taken:
        // above every number on record, in the order they began, in both
        // tables (the wide key stays unique: above its max too).
        $renumbered = 0;
        if ($clashes > 0) {
            $baseRows = $db->query("SELECT GREATEST(
                    (SELECT `last_iteration` FROM `survey_studies` WHERE `id` = {$studyId}),
                    (SELECT COALESCE(MAX(`study_iteration`), 0) FROM `survey_unit_sessions` WHERE `unit_id` = {$studyId}),
                    (SELECT COALESCE(MAX(`iteration`), 0) FROM `{$safeWide}`)) AS `base`");
            $base = isset($baseRows[0]['base']) ? (int) $baseRows[0]['base'] : 0;
            $renumberStmt = $db->query("
                UPDATE `survey_unit_sessions` AS `us`
                INNER JOIN `{$safeWide}` AS `w` ON `w`.`session_id` = `us`.`id`
                INNER JOIN (
                    SELECT `w2`.`session_id`, ROW_NUMBER() OVER (ORDER BY `w2`.`created`, `w2`.`session_id`) AS `n`
                      FROM `{$safeWide}` `w2`
                      INNER JOIN `survey_unit_sessions` `us2` ON `us2`.`id` = `w2`.`session_id`
                     WHERE `us2`.`study_iteration` IS NULL AND `w2`.`iteration` IS NOT NULL
                ) `r` ON `r`.`session_id` = `w`.`session_id`
                SET `us`.`study_iteration` = {$base} + `r`.`n`, `w`.`iteration` = {$base} + `r`.`n`
            ");
            $renumbered = (int) ($renumberStmt->rowCount() / 2);
            $rowsUpdated += $renumbered;
        }

        $maxRows = $db->query("SELECT GREATEST(COALESCE((SELECT MAX(`iteration`) FROM `{$safeWide}`), 0),
                COALESCE((SELECT MAX(`study_iteration`) FROM `survey_unit_sessions` WHERE `unit_id` = {$studyId}), 0)) AS `max_iter`");
        $maxIter = isset($maxRows[0]['max_iter']) ? (int) $maxRows[0]['max_iter'] : $maxIter;
        $newLastIteration = max($lastIteration, $maxIter);
        // $maxIter is interpolated as an int on purpose: a bound param
        // arrives as a string, and GREATEST() with any string argument
        // compares as strings ('3' > '10').
        $db->exec(
            "UPDATE `survey_studies` SET `last_iteration` = GREATEST(`last_iteration`, {$maxIter}) WHERE `id` = :id",
            ['id' => $studyId]
        );
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        fprintf(STDERR, "study %d: FAILED — %s\n", $studyId, $e->getMessage());
        return ['rows' => 0, 'renumbered' => 0, 'newLastIteration' => $lastIteration, 'failed' => true];
    }

    return ['rows' => $rowsUpdated, 'renumbered' => $renumbered, 'newLastIteration' => $newLastIteration];
}

/**
 * No-iteration-column path (pre-2024-07 wide tables): there is no
 * historical value to copy, so NULL study_iteration rows matched to a
 * wide row (session_id join) are numbered sequentially in
 * wide.created order, starting just above the highest study_iteration
 * already allocated for this study — either through a matching wide
 * row, or (if dual-write was ever off) survey_studies.last_iteration
 * itself — so newly-assigned historical numbers can never collide
 * with numbers already handed out live.
 *
 * @return array{rows:int, newLastIteration:int}
 */
function backfillSequentially($db, $safeWide, $studyId, $lastIteration, $dryRun) {
    $rows = $db->query("SELECT `us`.`id` AS session_pk FROM `{$safeWide}` `w`
        INNER JOIN `survey_unit_sessions` `us` ON `us`.`id` = `w`.`session_id`
        WHERE `us`.`study_iteration` IS NULL
        ORDER BY `w`.`created` ASC, `w`.`session_id` ASC");
    $toAssign = array_map(function ($r) { return (int) $r['session_pk']; }, $rows);
    $count = count($toAssign);

    $maxRows = $db->query("SELECT COALESCE(MAX(`us`.`study_iteration`), 0) AS max_iter FROM `{$safeWide}` `w`
        INNER JOIN `survey_unit_sessions` `us` ON `us`.`id` = `w`.`session_id`");
    $existingMax = isset($maxRows[0]['max_iter']) ? (int) $maxRows[0]['max_iter'] : 0;
    $start = max($existingMax, $lastIteration);
    $newLastIteration = $start + $count;

    if ($dryRun) {
        return ['rows' => $count, 'newLastIteration' => $newLastIteration];
    }

    // Run the assignment loop and the last_iteration bump together even
    // when $count is 0: a study can have nothing left to backfill but
    // still have last_iteration lagging already-allocated study_iteration
    // values (drift — requirement 4), and that only gets fixed if this
    // transaction actually runs.
    $db->beginTransaction();
    try {
        $next = $start + 1;
        foreach ($toAssign as $sessionPk) {
            $db->update('survey_unit_sessions',
                ['study_iteration' => $next],
                ['id' => $sessionPk]);
            $next++;
        }

        $db->exec(
            "UPDATE `survey_studies` SET `last_iteration` = GREATEST(`last_iteration`, {$newLastIteration}) WHERE `id` = :id",
            ['id' => $studyId]
        );
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        fprintf(STDERR, "study %d: FAILED — %s\n", $studyId, $e->getMessage());
        return ['rows' => 0, 'newLastIteration' => $lastIteration, 'failed' => true];
    }

    return ['rows' => $count, 'newLastIteration' => $newLastIteration];
}
