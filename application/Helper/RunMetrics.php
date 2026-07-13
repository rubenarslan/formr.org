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

    /**
     * Full ground-truth recompute of both rollups (run + study) — the nightly
     * reconciliation / drift-correction pass and the migration seed. This is the
     * only place that scans history; write-time hooks (survey start/complete)
     * keep study response counts fresh between runs. Config-gated: an instance
     * that does not watch compute can set `metrics_reconcile_enabled=false` to
     * skip the scan entirely (its write-hooked study counts still self-maintain;
     * only the run/compute rollup goes stale). No-op returns -1.
     */
    public static function reconcile(): int {
        if (!Config::get('metrics_reconcile_enabled', true)) {
            return -1;
        }
        $affected = self::reconcileRunMetrics();
        StudyMetrics::reconcileAll();
        return $affected;
    }

    /** @deprecated use reconcile(); retained so existing cron callers keep working. */
    public static function refresh(): int {
        return self::reconcile();
    }

    /** Recompute every run's rollup row (session counts, compute sums, log counts). */
    private static function reconcileRunMetrics(): int {
        $db = DB::getInstance();
        $sql = "
            INSERT INTO `survey_run_metrics`
              (run_id, n_run_sessions, last_access, n_unit_sessions, n_push_logs, n_email_logs,
               n_exec_sessions, total_execution_time, month_execution_time, month_key,
               max_execution_time, last_activity)
            SELECT r.id,
                   COALESCE(rs.n_run_sessions, 0), rs.last_access,
                   COALESCE(ua.n_unit_sessions, 0), COALESCE(pl.n_push_logs, 0), COALESCE(el.n_email_logs, 0),
                   COALESCE(us.n_exec_sessions, 0), COALESCE(us.total_time, 0),
                   COALESCE(us.month_time, 0), DATE_FORMAT(NOW(), '%Y-%m'),
                   us.max_time, us.last_activity
            FROM `survey_runs` r
            LEFT JOIN (
                SELECT run_id, COUNT(*) AS n_run_sessions, MAX(last_access) AS last_access
                FROM `survey_run_sessions` GROUP BY run_id
            ) rs ON rs.run_id = r.id
            LEFT JOIN (
                SELECT rs2.run_id, COUNT(*) AS n_unit_sessions
                FROM `survey_unit_sessions` us2 JOIN `survey_run_sessions` rs2 ON rs2.id = us2.run_session_id
                GROUP BY rs2.run_id
            ) ua ON ua.run_id = r.id
            LEFT JOIN (
                SELECT run_id, COUNT(*) AS n_push_logs FROM `push_logs` GROUP BY run_id
            ) pl ON pl.run_id = r.id
            LEFT JOIN (
                SELECT rs3.run_id, COUNT(*) AS n_email_logs
                FROM `survey_email_log` el3 JOIN `survey_unit_sessions` us3 ON us3.id = el3.session_id
                JOIN `survey_run_sessions` rs3 ON rs3.id = us3.run_session_id
                GROUP BY rs3.run_id
            ) el ON el.run_id = r.id
            LEFT JOIN (
                SELECT rs4.run_id,
                       COUNT(us4.id) AS n_exec_sessions,
                       SUM(us4.execution_time) AS total_time,
                       SUM(CASE WHEN us4.created >= DATE_FORMAT(NOW(), '%Y-%m-01')
                                THEN us4.execution_time ELSE 0 END) AS month_time,
                       MAX(us4.execution_time) AS max_time,
                       MAX(us4.created) AS last_activity
                FROM `survey_unit_sessions` us4
                JOIN `survey_run_sessions` rs4 ON rs4.id = us4.run_session_id
                WHERE us4.execution_time IS NOT NULL
                GROUP BY rs4.run_id
            ) us ON us.run_id = r.id
            ON DUPLICATE KEY UPDATE
                n_run_sessions = VALUES(n_run_sessions), last_access = VALUES(last_access),
                n_unit_sessions = VALUES(n_unit_sessions), n_push_logs = VALUES(n_push_logs),
                n_email_logs = VALUES(n_email_logs), n_exec_sessions = VALUES(n_exec_sessions),
                total_execution_time = VALUES(total_execution_time),
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
