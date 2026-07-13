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
 * is a tester. begun/finished partition real users by completion.
 */
class StudyMetrics {

    /** True iff the run-session testing flag denotes a real (non-test) user. */
    private static function isReal($testing): bool {
        return $testing !== null && (int) $testing === 0;
    }

    /** Write hook: a participant just started this study (first results row). */
    public static function onSurveyStart(int $studyId, $testing): void {
        $real = self::isReal($testing) ? 1 : 0;
        self::bump($studyId, [
            'begun' => $real, 'real_users' => $real, 'testers' => $real ? 0 : 1,
        ]);
    }

    /**
     * Write hook: a participant just completed this study (ended stamped).
     * Duration (all completions, testers included) feeds the geometric mean as
     * LN(GREATEST(seconds, 1)) — no exclusion; sub-second/degenerate floors to 0.
     */
    public static function onSurveyComplete(int $studyId, $testing, int $seconds): void {
        $real = self::isReal($testing) ? 1 : 0;
        $slog = log(max($seconds, 1));
        $db = DB::getInstance();
        $db->exec(
            "INSERT INTO `survey_study_metrics` (study_id, finished, begun, n_durations, sum_log_duration)
             VALUES (:sid, :fin, 0, 1, :slog)
             ON DUPLICATE KEY UPDATE
                finished = finished + :fin,
                begun = GREATEST(CAST(begun AS SIGNED) - :fin, 0),
                n_durations = n_durations + 1,
                sum_log_duration = sum_log_duration + :slog",
            ['sid' => $studyId, 'fin' => $real, 'slog' => $slog]
        );
    }

    /** Additive upsert of a set of count columns for one study. */
    private static function bump(int $studyId, array $deltas): void {
        $cols = array_keys($deltas);
        $insertVals = implode(', ', array_map(fn($c) => ':' . $c, $cols));
        $updates = implode(', ', array_map(fn($c) => "`$c` = `$c` + :$c", $cols));
        $params = ['sid' => $studyId] + $deltas;
        DB::getInstance()->exec(
            "INSERT INTO `survey_study_metrics` (study_id, " . implode(', ', $cols) . ")
             VALUES (:sid, {$insertVals})
             ON DUPLICATE KEY UPDATE {$updates}",
            $params
        );
    }

    /** Read: begun/finished/testers/real_users for a study (0s if no row yet). */
    public static function counts(int $studyId): array {
        $row = DB::getInstance()->execute(
            "SELECT begun, finished, testers, real_users FROM `survey_study_metrics` WHERE study_id = :sid",
            ['sid' => $studyId], false, true
        );
        return $row ?: ['begun' => 0, 'finished' => 0, 'testers' => 0, 'real_users' => 0];
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
        $agg = $db->execute("
            SELECT
              SUM(rs.testing IS NOT NULL AND rs.testing = 0 AND rt.ended IS NULL)     AS begun,
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
               (study_id, begun, finished, testers, real_users, sum_log_duration, n_durations)
             VALUES (:sid, :begun, :finished, :testers, :real_users, :slog, :ndur)
             ON DUPLICATE KEY UPDATE
               begun = VALUES(begun), finished = VALUES(finished), testers = VALUES(testers),
               real_users = VALUES(real_users), sum_log_duration = VALUES(sum_log_duration),
               n_durations = VALUES(n_durations)",
            [
                'sid' => $studyId,
                'begun' => (int) ($agg['begun'] ?? 0), 'finished' => (int) ($agg['finished'] ?? 0),
                'testers' => (int) ($agg['testers'] ?? 0), 'real_users' => (int) ($agg['real_users'] ?? 0),
                'slog' => (float) ($agg['sum_log_duration'] ?? 0), 'ndur' => (int) ($agg['n_durations'] ?? 0),
            ]
        );
    }
}
