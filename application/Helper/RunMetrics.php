<?php

/**
 * Maintained per-run rollup of the aggregates that would otherwise re-scan the
 * full survey_run_sessions / survey_unit_sessions history on every dashboard or
 * admin-list view (slow-query audit 2026-07 §6.2 — SQ-13/16/17/18/21).
 *
 * Ownership split (review 2026-07, item 7):
 *  - month_execution_time / month_key are WRITE-TIME-OWNED: bumped by
 *    addMonthExecution() from UnitSession::addExecutionTime on every execute()
 *    pass, so "this month's compute" attributes to the month the work HAPPENED
 *    (a lifetime-cumulative execution_time filtered by us.created attributed a
 *    long-lived session's entire burn to its creation month — recurring
 *    recheck-loop compute escaped every later month's budget). History cannot
 *    reproduce this split, so the reconcile PRESERVES the bucket within the
 *    current month and only zeroes it at month rollover. ComputeLimitCron
 *    enforcement reads this bucket — fresh by construction (written in the
 *    same breath as the underlying data), unlike the reconcile-owned columns.
 *  - Everything else is RECONCILE-OWNED: recomputable from history, refreshed
 *    nightly, display-only; bounded staleness never affects a quota decision.
 * The FK cascade drops rows for deleted runs. Reads LEFT JOIN the rollup, so a
 * run created since the last refresh simply reads as 0 until the next pass
 * (or until its first addMonthExecution upsert creates the row).
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

    /**
     * Freshen the run rollup on demand when it is older than $maxAgeSeconds —
     * for the superadmin compute-usage dashboard, which must reflect recent
     * compute (e.g. right after a heavy run) rather than wait for the nightly
     * reconcile. Rarely-viewed tab, so one scan per view-burst is acceptable;
     * the TTL dedupes rapid refreshes. Returns -1 (gate off), 0 (already fresh),
     * or the reconciled row count. Reconciles run metrics only (what the
     * dashboard reads) — study counts are kept fresh by their write hooks.
     */
    public static function reconcileIfStale(int $maxAgeSeconds = 30): int {
        if (!Config::get('metrics_reconcile_enabled', true)) {
            return -1;
        }
        $fresh = DB::getInstance()->execute(
            "SELECT MAX(updated_at) >= (NOW() - INTERVAL :ttl SECOND) FROM survey_run_metrics",
            ['ttl' => max(0, $maxAgeSeconds)], true
        );
        if ($fresh) {
            return 0;
        }
        return self::reconcileRunMetrics();
    }

    /** Recompute every run's reconcile-owned rollup columns (session counts,
     * lifetime compute sums, log counts). The month bucket is write-time-owned
     * (addMonthExecution): the reconcile PRESERVES it while month_key is
     * current and only zeroes it at month rollover — history cannot reproduce
     * the executed-this-month split, so recomputing it here would clobber the
     * only correct copy with a created-date approximation. */
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
                   0, DATE_FORMAT(NOW(), '%Y-%m'),
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
                -- month bucket is write-time-owned: keep it while the month is
                -- current, zero it at rollover (compare BEFORE month_key is
                -- restamped on the next line — assignments run left to right)
                month_execution_time = CASE WHEN month_key = VALUES(month_key)
                                            THEN month_execution_time ELSE 0 END,
                month_key = VALUES(month_key),
                max_execution_time = VALUES(max_execution_time), last_activity = VALUES(last_activity)
        ";
        return $db->exec($sql);
    }

    /** Current-month key ('YYYY-MM'); month_execution_time is only valid for this. */
    public static function monthKey(): string {
        return date('Y-m');
    }

    /**
     * Write-time month-bucket bump (review 2026-07, item 7): attribute
     * $seconds of execution to THIS month on the run's rollup row, creating
     * the row if the reconcile hasn't seen the run yet. Same-month bumps
     * accumulate; the first bump of a new month resets the bucket (assignment
     * order matters: the bucket CASE reads month_key before it is restamped).
     * Called from UnitSession::addExecutionTime on every measured pass — keep
     * it a single cheap upsert.
     */
    public static function addMonthExecution(int $runId, float $seconds): void {
        if ($runId <= 0 || $seconds <= 0) {
            return;
        }
        DB::getInstance()->exec(
            "INSERT INTO `survey_run_metrics` (run_id, month_execution_time, month_key)
             VALUES (:rid, :delta, :ym)
             ON DUPLICATE KEY UPDATE
                month_execution_time = CASE WHEN month_key = VALUES(month_key)
                                            THEN month_execution_time + VALUES(month_execution_time)
                                            ELSE VALUES(month_execution_time) END,
                month_key = VALUES(month_key)",
            ['rid' => $runId, 'delta' => round($seconds, 3), 'ym' => self::monthKey()]
        );
    }

    /**
     * Read a single reconcile-maintained count column for a run (SQ-06/14/37
     * pagination), or null when there is no usable rollup value — caller falls
     * back to a live COUNT. Reconcile-fresh (nightly); tolerant of day-staleness.
     *
     * A value of 0 is treated like a missing row: patch 068 seeded a rollup
     * row for every existing run and patch 069 added these columns DEFAULT 0
     * with no backfill, so 0 is indistinguishable from "never reconciled" —
     * served as-is it blanked every admin email/push/user-detail table until
     * the first nightly pass (forever with metrics_reconcile_enabled=false).
     * The fallback live COUNT is cheap when the run is genuinely empty, and
     * correct-at-pre-rollup-cost when the zero is stale.
     */
    public static function count(int $runId, string $col): ?int {
        $allowed = ['n_run_sessions', 'n_unit_sessions', 'n_push_logs', 'n_email_logs'];
        if (!in_array($col, $allowed, true)) {
            throw new InvalidArgumentException("RunMetrics::count unknown column {$col}");
        }
        $val = DB::getInstance()->execute(
            "SELECT `{$col}` FROM `survey_run_metrics` WHERE run_id = :rid",
            ['rid' => $runId], true
        );
        return $val === false || $val === null || (int) $val === 0 ? null : (int) $val;
    }

    /** Per-user compute across the instance (SQ-16 usageByUser). */
    public static function usageByUser(): array {
        $stmt = DB::getInstance()->prepare("
            SELECT u.id, u.email, u.compute_limit_monthly,
                   COUNT(m.run_id) AS n_runs,
                   SUM(m.n_exec_sessions) AS n_sessions,
                   ROUND(SUM(m.total_execution_time), 1) AS total_time,
                   ROUND(SUM(CASE WHEN m.month_key = :month_key
                                  THEN m.month_execution_time ELSE 0 END), 1) AS month_time,
                   (SELECT COUNT(*) FROM survey_runs r2
                     WHERE r2.user_id = u.id AND r2.compute_closed_from IS NOT NULL
                       AND r2.public = 0 AND r2.cron_active = 0) AS paused_runs
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
