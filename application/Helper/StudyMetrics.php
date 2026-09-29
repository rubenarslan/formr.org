<?php

/**
 * Per-study (SurveyStudy) response-count + duration rollup — write-time
 * accounting for SurveyStudy::getResultCount (SQ-11) and the geometric-mean
 * completion duration that replaces the removed median (SQ-10). See
 * documentation/agent_doc/write_time_metrics_plan.md.
 *
 * The response counts are kept FRESH by the write hooks below (onSurveyStart /
 * onSurveyComplete), so a researcher's "how many responses" view is current
 * without any scan. reconcileAll() is the nightly ground-truth pass that also
 * corrects drift from the rarer paths left un-hooked (testing-toggle, deletes).
 *
 * "real" vs "tester" follows getResultCount exactly: a session is a real user
 * iff its run-session testing flag is non-null AND 0; anything else (null or 1)
 * is a tester. begun (real_users - finished, derived — not stored)
 * and finished partition real users by completion.
 */
class StudyMetrics {

    /** True iff the run-session testing flag denotes a real (non-test) user. */
    private static function isReal($testing): bool {
        return $testing !== null && (int) $testing === 0;
    }

    /** Write hook: a participant just started this study (first results row). */
    public static function onSurveyStart(int $studyId, $testing): void {
        // Self-guarded: metrics accounting is best-effort and must never break
        // a participant's run — callers are plain one-liners so a future hook
        // author can't forget the wrapper (review 2026-07 cleanup).
        try {
            $real = self::isReal($testing) ? 1 : 0;
            // `begun` is not stored — it is real_users - finished, derived in
            // counts() (review 2026-07 cleanup #4). Only the independent
            // counters are bumped here.
            self::bump($studyId, [
                'real_users' => $real, 'testers' => $real ? 0 : 1,
            ]);
        } catch (Throwable $e) {
            formr_log_exception($e, 'StudyMetrics::onSurveyStart');
        }
    }

    /**
     * Duration expression shared by recordCompletion() and the long-path
     * reconcile: seconds from the session's first display row (stamped by
     * createSurveyStudyRecord at first render — the same instant the wide
     * row's `created` used to record) to survey_unit_sessions.ended, falling
     * back to the unit session's own `created` when no display rows exist.
     * Requires `us` (survey_unit_sessions) joined to `sid`
     * (survey_items_display) and a GROUP BY on us.id.
     */
    private const LONG_DURATION_SQL =
        "TIMESTAMPDIFF(SECOND, COALESCE(MIN(`sid`.`created`), `us`.`created`), `us`.`ended`)";

    /**
     * Write hook from UnitSession::end(): a participant just completed this
     * study. Called after survey_unit_sessions.ended has been stamped, and
     * independently of form_v2_dual_write_results (F5) — the duration is read
     * from the long tables, never from the wide row. Self-guarded.
     */
    public static function recordCompletion(int $studyId, $testing, int $sessionId): void {
        try {
            $seconds = (int) DB::getInstance()->execute(
                "SELECT " . self::LONG_DURATION_SQL . "
                 FROM `survey_unit_sessions` `us`
                 LEFT JOIN `survey_items_display` `sid` ON `sid`.`session_id` = `us`.`id`
                 WHERE `us`.`id` = :sid AND " . self::studySessionsSql('us', 'stid') . "
                 GROUP BY `us`.`id`, `us`.`created`, `us`.`ended`",
                ['sid' => $sessionId, 'stid' => $studyId, 'stid_form' => $studyId], true
            );
            self::onSurveyComplete($studyId, $testing, $seconds);
        } catch (Throwable $e) {
            formr_log_exception($e, 'StudyMetrics::recordCompletion');
        }
    }

    /**
     * WHERE fragment: `$alias` is a unit session of the study bound to :$param.
     * A Survey session's unit_id IS the study id; a Form session's unit_id is
     * the Form's own survey_units row, which names the study in form_study_id
     * (patch 101). Binds :$param and :{$param}_form (native prepares cannot
     * reuse a placeholder).
     */
    private static function studySessionsSql(string $alias, string $param): string {
        return "(`{$alias}`.`unit_id` = :{$param} OR `{$alias}`.`unit_id` IN "
            . "(SELECT `id` FROM `survey_units` WHERE `type` = 'Form' AND `form_study_id` = :{$param}_form))";
    }

    /**
     * Record one completion of $seconds. Duration (all completions, testers
     * included) feeds the geometric mean as LN(GREATEST(seconds, 1)) — no
     * exclusion; sub-second/degenerate floors to 0. Self-guarded.
     */
    public static function onSurveyComplete(int $studyId, $testing, int $seconds): void {
        try {
            $real = self::isReal($testing) ? 1 : 0;
            $slog = log(max($seconds, 1));
            // VALUES(col) in the UPDATE clause avoids re-binding a named param
            // twice (DB uses non-emulated prepares, which forbid duplicates).
            // `begun` is derived (real_users - finished), so a completion only
            // increments `finished` — no clamped decrement to keep in step
            // (review 2026-07 cleanup #4).
            DB::getInstance()->exec(
                "INSERT INTO `survey_study_metrics` (study_id, finished, n_durations, sum_log_duration)
                 VALUES (:sid, :fin, 1, :slog)
                 ON DUPLICATE KEY UPDATE
                    finished = finished + VALUES(finished),
                    n_durations = n_durations + VALUES(n_durations),
                    sum_log_duration = sum_log_duration + VALUES(sum_log_duration)",
                ['sid' => $studyId, 'fin' => $real, 'slog' => $slog]
            );
        } catch (Throwable $e) {
            formr_log_exception($e, 'StudyMetrics::onSurveyComplete');
        }
    }

    /** Additive upsert of a set of count columns for one study. */
    private static function bump(int $studyId, array $deltas): void {
        $cols = array_keys($deltas);
        $insertVals = implode(', ', array_map(fn($c) => ':' . $c, $cols));
        // VALUES(col) in the UPDATE clause — a named param can't appear twice
        // under non-emulated prepares.
        $updates = implode(', ', array_map(fn($c) => "`$c` = `$c` + VALUES(`$c`)", $cols));
        $params = ['sid' => $studyId] + $deltas;
        DB::getInstance()->exec(
            "INSERT INTO `survey_study_metrics` (study_id, " . implode(', ', $cols) . ")
             VALUES (:sid, {$insertVals})
             ON DUPLICATE KEY UPDATE {$updates}",
            $params
        );
    }

    /**
     * Read: begun/finished/testers/real_users for a study, or null when the
     * study has no rollup row yet (caller falls back to a live count — cheap,
     * since a study with no row has never been reconciled or started).
     */
    public static function counts(int $studyId): ?array {
        $row = DB::getInstance()->execute(
            "SELECT finished, testers, real_users FROM `survey_study_metrics` WHERE study_id = :sid",
            ['sid' => $studyId], false, true
        );
        if (!$row) {
            return null;
        }
        $row = array_map('intval', $row);
        // begun = real users who started but have not finished; derived, not
        // stored (review 2026-07 cleanup #4). max(…,0) floors any transient
        // inconsistency between the write hooks.
        $row['begun'] = max($row['real_users'] - $row['finished'], 0);
        return $row;
    }

    /** Read: geometric-mean completion duration in seconds, or null if none. */
    public static function geometricMeanSeconds(int $studyId): ?float {
        $row = DB::getInstance()->execute(
            "SELECT n_durations, sum_log_duration FROM `survey_study_metrics` WHERE study_id = :sid",
            ['sid' => $studyId], false, true
        );
        if (!$row || (int) $row['n_durations'] === 0) {
            return null;
        }
        return exp((float) $row['sum_log_duration'] / (int) $row['n_durations']);
    }

    /** Nightly ground-truth recompute for every study with a results table. */
    public static function reconcileAll(): void {
        $studies = DB::getInstance()->execute(
            "SELECT id, results_table FROM `survey_studies`
             WHERE results_table IS NOT NULL AND results_table != ''"
        );
        foreach ($studies as $s) {
            self::reconcileStudy((int) $s['id'], $s['results_table']);
        }
    }

    /**
     * Recompute one study's counts + duration accumulator from ground truth.
     *
     * With form_v2_dual_write_results on (and a wide table present) the wide
     * table is the ground truth, as before. Otherwise (F5) the long tables
     * are: one row per survey_unit_sessions row of this study, "ended" taken
     * as UnitSession::completedEndedSql() — i.e. operator force-moves
     * (result in OPERATOR_END_RESULTS) are not completions, matching what the
     * long read reports as `ended` and what recordCompletion() feeds live.
     * Only sessions that reached first render count as "started" (they hold
     * a study_iteration or display rows), mirroring the wide path, where a
     * row exists only once createSurveyStudyRecord ran.
     */
    private static function reconcileStudy(int $studyId, string $resultsTable): void {
        $db = DB::getInstance();
        $useWide = (bool) Config::get('form_v2_dual_write_results', true) && $db->table_exists($resultsTable);
        // begun is derived (real_users - finished) — not aggregated or stored.
        if ($useWide) {
            // identifier, not user input: study results tables are app-created (s<N>_…)
            $rt = str_replace('`', '', $resultsTable);
            $agg = $db->execute("
                SELECT
                  SUM(rs.testing IS NOT NULL AND rs.testing = 0 AND rt.ended IS NOT NULL)  AS finished,
                  SUM(rs.testing IS NULL OR rs.testing = 1)                               AS testers,
                  SUM(rs.testing IS NOT NULL AND rs.testing = 0)                          AS real_users,
                  SUM(CASE WHEN rt.ended IS NOT NULL
                           THEN LN(GREATEST(TIMESTAMPDIFF(SECOND, rt.created, rt.ended), 1)) ELSE 0 END) AS sum_log_duration,
                  SUM(rt.ended IS NOT NULL)                                               AS n_durations
                FROM `{$rt}` rt
                LEFT JOIN `survey_unit_sessions` us ON us.id = rt.session_id
                LEFT JOIN `survey_run_sessions` rs ON us.run_session_id = rs.id
            ", [], false, true);
        } else {
            $endedSql = UnitSession::completedEndedSql('us');
            // us.study_iteration is in the GROUP BY because the HAVING reads
            // it: MariaDB resolves only grouped or aggregated columns there
            // and does not infer them from the us.id primary key (1054).
            $agg = $db->execute("
                SELECT
                  SUM(t.real_user AND t.ended IS NOT NULL)                                AS finished,
                  SUM(NOT t.real_user)                                                    AS testers,
                  SUM(t.real_user)                                                        AS real_users,
                  SUM(CASE WHEN t.ended IS NOT NULL
                           THEN LN(GREATEST(t.seconds, 1)) ELSE 0 END)                    AS sum_log_duration,
                  SUM(t.ended IS NOT NULL)                                                AS n_durations
                FROM (
                  SELECT
                    (rs.testing IS NOT NULL AND rs.testing = 0) AS real_user,
                    {$endedSql} AS ended,
                    " . self::LONG_DURATION_SQL . " AS seconds
                  FROM `survey_unit_sessions` us
                  LEFT JOIN `survey_run_sessions` rs ON us.run_session_id = rs.id
                  LEFT JOIN `survey_items_display` sid ON sid.session_id = us.id
                  WHERE " . self::studySessionsSql('us', 'unit_id') . "
                  GROUP BY us.id, us.created, us.ended, us.result, us.study_iteration, rs.testing
                  HAVING us.study_iteration IS NOT NULL OR COUNT(sid.item_id) > 0
                ) t
            ", ['unit_id' => $studyId, 'unit_id_form' => $studyId], false, true);
        }
        $db->exec(
            "INSERT INTO `survey_study_metrics`
               (study_id, finished, testers, real_users, sum_log_duration, n_durations)
             VALUES (:sid, :finished, :testers, :real_users, :slog, :ndur)
             ON DUPLICATE KEY UPDATE
               finished = VALUES(finished), testers = VALUES(testers),
               real_users = VALUES(real_users), sum_log_duration = VALUES(sum_log_duration),
               n_durations = VALUES(n_durations)",
            [
                'sid' => $studyId,
                'finished' => (int) ($agg['finished'] ?? 0),
                'testers' => (int) ($agg['testers'] ?? 0), 'real_users' => (int) ($agg['real_users'] ?? 0),
                'slog' => (float) ($agg['sum_log_duration'] ?? 0), 'ndur' => (int) ($agg['n_durations'] ?? 0),
            ]
        );
    }
}
