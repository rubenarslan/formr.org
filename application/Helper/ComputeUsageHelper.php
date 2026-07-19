<?php

/**
 * Aggregates unit-session execution time (issue #608) for the compute-usage
 * dashboards. Reads the `survey_unit_sessions.execution_time` column written
 * by UnitSession::execute(). Rows with NULL execution_time (created before the
 * feature shipped) are excluded so totals reflect only measured compute.
 *
 * Joins: survey_unit_sessions → survey_run_sessions → survey_runs → survey_users.
 * The run_session path is used (rather than unit → run) because it is a single
 * indexed FK hop and carries the run owner.
 */
class ComputeUsageHelper {

    protected static function fetchAll(string $query, array $binds = array()): array {
        $stmt = DB::getInstance()->prepare($query);
        $stmt->execute($binds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    protected static function fetchRow(string $query, array $binds = array()): array {
        $rows = self::fetchAll($query, $binds);
        return $rows ? $rows[0] : array();
    }

    /**
     * Per-run compute for a single study admin (their own runs only).
     */
    public static function runUsageForUser(int $userId): array {
        return self::fetchAll("
            SELECT r.id AS run_id, r.name AS run_name,
                   COUNT(us.id) AS n_sessions,
                   ROUND(SUM(us.execution_time), 1) AS total_time,
                   ROUND(AVG(us.execution_time), 3) AS avg_time,
                   MAX(us.execution_time) AS max_time,
                   MAX(us.created) AS last_activity
            FROM survey_runs r
            JOIN survey_run_sessions rs ON rs.run_id = r.id
            JOIN survey_unit_sessions us ON us.run_session_id = rs.id
            WHERE r.user_id = :user_id AND us.execution_time IS NOT NULL
            GROUP BY r.id, r.name
            ORDER BY total_time DESC
        ", array(':user_id' => $userId));
    }

    /**
     * Headline totals for a single study admin: all-time and current month.
     */
    public static function totalsForUser(int $userId): array {
        // month_time reads the write-time month bucket (review 2026-07, item
        // 7) so the dashboard agrees with enforcement and charges compute to
        // the month it happened; total/n_sessions stay live (lifetime values
        // are recomputable and this per-user scan is index-served).
        return self::fetchRow("
            SELECT ROUND(SUM(us.execution_time), 1) AS total_time,
                   (SELECT ROUND(COALESCE(SUM(CASE WHEN m.month_key = :month_key
                                                   THEN m.month_execution_time END), 0), 1)
                      FROM survey_run_metrics m
                      JOIN survey_runs r2 ON r2.id = m.run_id
                     WHERE r2.user_id = :user_id2) AS month_time,
                   COUNT(us.id) AS n_sessions
            FROM survey_runs r
            JOIN survey_run_sessions rs ON rs.run_id = r.id
            JOIN survey_unit_sessions us ON us.run_session_id = rs.id
            WHERE r.user_id = :user_id AND us.execution_time IS NOT NULL
        ", array(':user_id' => $userId, ':user_id2' => $userId,
                 ':month_key' => RunMetrics::monthKey()));
    }

    /**
     * Per-user compute across the whole instance (superadmin view).
     * Served from the maintained per-run rollup (audit SQ-16) instead of
     * re-scanning the full unit-session history on every dashboard view.
     */
    public static function usageByUser(): array {
        return RunMetrics::usageByUser();
    }

    /**
     * Instance-wide default monthly compute budget in seconds (issue #608).
     * 0 = unlimited. Used as the effective limit when a user has no override.
     */
    public static function monthlyDefault(): float {
        return (float) Config::get('compute_limit_monthly_default', 0);
    }

    /**
     * Effective monthly limit in seconds for a user row: the per-user override
     * when set, else the global default. 0 = unlimited.
     */
    public static function effectiveLimit($override): float {
        return ($override !== null && $override !== '') ? (float) $override : self::monthlyDefault();
    }

    /** Human-readable rendering of a limit value: "unlimited" for 0. */
    public static function formatLimit($seconds): string {
        return ((float) $seconds) > 0 ? self::formatDuration($seconds) : 'unlimited';
    }

    /**
     * Heaviest runs across the whole instance (superadmin view).
     * Served from the per-run rollup (audit SQ-17).
     */
    public static function topRuns(int $limit = 50): array {
        return RunMetrics::topRuns($limit);
    }

    /**
     * Instance-wide headline totals (superadmin view).
     * Served from the per-run rollup (audit SQ-18).
     */
    public static function totalsForInstance(): array {
        return RunMetrics::totalsForInstance();
    }

    /**
     * Render seconds as a compact human string: "1h 23m", "4m 05s", "2.4s".
     */
    public static function formatDuration($seconds): string {
        $seconds = (float) $seconds;
        if ($seconds <= 0) {
            return '0s';
        }
        if ($seconds < 60) {
            return rtrim(rtrim(number_format($seconds, 1), '0'), '.') . 's';
        }
        $h = (int) floor($seconds / 3600);
        $m = (int) floor(($seconds % 3600) / 60);
        $s = (int) floor($seconds % 60);
        if ($h > 0) {
            return sprintf('%dh %02dm', $h, $m);
        }
        return sprintf('%dm %02ds', $m, $s);
    }
}
