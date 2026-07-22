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
     * Write hook from UnitSession::end(): a participant just completed this
     * study. Owns the duration measurement (one indexed lookup on the results
     * table) so the caller stays a guard-free one-liner. Self-guarded.
     */
    public static function recordCompletion(int $studyId, $testing, string $resultsTable, int $sessionId): void {
        try {
            $rt = str_replace('`', '', $resultsTable);
            $seconds = (int) DB::getInstance()->execute(
                "SELECT TIMESTAMPDIFF(SECOND, `created`, `ended`) FROM `{$rt}`
                 WHERE session_id = :sid AND study_id = :stid",
                ['sid' => $sessionId, 'stid' => $studyId], true
            );
            self::onSurveyComplete($studyId, $testing, $seconds);
        } catch (Throwable $e) {
            formr_log_exception($e, 'StudyMetrics::recordCompletion');
        }
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

    /** Recompute one study's counts + duration accumulator from ground truth. */
    private static function reconcileStudy(int $studyId, string $resultsTable): void {
        $db = DB::getInstance();
        if (!$db->table_exists($resultsTable)) {
            return;
        }
        // identifier, not user input: study results tables are app-created (s<N>_…)
        $rt = str_replace('`', '', $resultsTable);
        // begun is derived (real_users - finished) — not aggregated or stored.
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
