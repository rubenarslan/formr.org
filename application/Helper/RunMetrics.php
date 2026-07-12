<?php

/**
 * Maintained per-run rollup of the aggregates that would otherwise re-scan the
 * full survey_run_sessions / survey_unit_sessions history on every dashboard or
 * admin-list view (slow-query audit 2026-07 §6.2 — SQ-13/16/17/18/21).
 *
 * Backs read-only display only. ComputeLimitCron enforcement keeps reading live,
 * so bounded staleness here never affects a quota/close decision. refresh() is a
 * single INSERT..SELECT..ON DUPLICATE KEY UPDATE over all runs (O(one scan) per
 * refresh instead of O(one scan) per page view); the FK cascade drops rows for
 * deleted runs. Reads LEFT JOIN the rollup, so a run created since the last
 * refresh simply reads as 0 until the next pass.
 */
class RunMetrics {

    /** Recompute every run's rollup row. Returns affected row count. */
    public static function refresh(): int {
        $db = DB::getInstance();
        $sql = "
            INSERT INTO `survey_run_metrics`
              (run_id, n_run_sessions, last_access, n_exec_sessions,
               total_execution_time, month_execution_time, month_key, max_execution_time, last_activity)
            SELECT r.id,
                   COALESCE(rs.n_run_sessions, 0), rs.last_access,
                   COALESCE(us.n_exec_sessions, 0), COALESCE(us.total_time, 0),
                   COALESCE(us.month_time, 0), DATE_FORMAT(NOW(), '%Y-%m'),
                   us.max_time, us.last_activity
            FROM `survey_runs` r
            LEFT JOIN (
                SELECT run_id, COUNT(*) AS n_run_sessions, MAX(last_access) AS last_access
                FROM `survey_run_sessions` GROUP BY run_id
            ) rs ON rs.run_id = r.id
            LEFT JOIN (
                SELECT rs2.run_id,
                       COUNT(us2.id) AS n_exec_sessions,
                       SUM(us2.execution_time) AS total_time,
                       SUM(CASE WHEN us2.created >= DATE_FORMAT(NOW(), '%Y-%m-01')
                                THEN us2.execution_time ELSE 0 END) AS month_time,
                       MAX(us2.execution_time) AS max_time,
                       MAX(us2.created) AS last_activity
                FROM `survey_unit_sessions` us2
                JOIN `survey_run_sessions` rs2 ON rs2.id = us2.run_session_id
                WHERE us2.execution_time IS NOT NULL
                GROUP BY rs2.run_id
            ) us ON us.run_id = r.id
            ON DUPLICATE KEY UPDATE
                n_run_sessions = VALUES(n_run_sessions), last_access = VALUES(last_access),
                n_exec_sessions = VALUES(n_exec_sessions), total_execution_time = VALUES(total_execution_time),
                month_execution_time = VALUES(month_execution_time), month_key = VALUES(month_key),
                max_execution_time = VALUES(max_execution_time), last_activity = VALUES(last_activity)
        ";
        return $db->exec($sql);
    }

    /** Current-month key ('YYYY-MM'); month_execution_time is only valid for this. */
    protected static function monthKey(): string {
        return date('Y-m');
    }

    /** Per-user compute across the instance (SQ-16 usageByUser). */
    public static function usageByUser(): array {
        $stmt = DB::getInstance()->prepare("
            SELECT u.id, u.email, u.compute_limit_monthly,
                   COUNT(m.run_id) AS n_runs,
                   SUM(m.n_exec_sessions) AS n_sessions,
                   ROUND(SUM(m.total_execution_time), 1) AS total_time,
                   ROUND(SUM(CASE WHEN m.month_key = :month_key
                                  THEN m.month_execution_time ELSE 0 END), 1) AS month_time
            FROM survey_users u
            JOIN survey_runs r ON r.user_id = u.id
            JOIN survey_run_metrics m ON m.run_id = r.id AND m.n_exec_sessions > 0
            GROUP BY u.id, u.email, u.compute_limit_monthly
            ORDER BY total_time DESC
        ");
        $stmt->execute(array(':month_key' => self::monthKey()));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Heaviest runs across the instance (SQ-17 topRuns). */
    public static function topRuns(int $limit = 50): array {
        $limit = max(1, min(500, $limit));
        $stmt = DB::getInstance()->prepare("
            SELECT r.id AS run_id, r.name AS run_name, u.email AS owner_email,
                   m.n_exec_sessions AS n_sessions,
                   ROUND(m.total_execution_time, 1) AS total_time,
                   ROUND(CASE WHEN m.n_exec_sessions > 0
                              THEN m.total_execution_time / m.n_exec_sessions ELSE 0 END, 3) AS avg_time
            FROM survey_run_metrics m
            JOIN survey_runs r ON r.id = m.run_id
            JOIN survey_users u ON u.id = r.user_id
            WHERE m.n_exec_sessions > 0
            ORDER BY m.total_execution_time DESC
            LIMIT {$limit}
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Instance-wide headline totals (SQ-18 totalsForInstance). */
    public static function totalsForInstance(): array {
        $stmt = DB::getInstance()->prepare("
            SELECT ROUND(SUM(total_execution_time), 1) AS total_time,
                   ROUND(SUM(CASE WHEN month_key = :month_key
                                  THEN month_execution_time ELSE 0 END), 1) AS month_time,
                   SUM(n_exec_sessions) AS n_sessions
            FROM survey_run_metrics
        ");
        $stmt->execute(array(':month_key' => self::monthKey()));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows ? $rows[0] : array();
    }
}
