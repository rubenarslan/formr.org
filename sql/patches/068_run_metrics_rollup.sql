-- Slow-query audit 2026-07, §6.2: per-run rollup for the aggregates that
-- otherwise re-scan the full survey_run_sessions/survey_unit_sessions history
-- on every dashboard/list view — SQ-13 (run list session counts), SQ-16/17/18
-- (ComputeUsageHelper usage-by-user / top-runs / instance totals), SQ-21
-- (active-users list). Maintained by RunMetrics::refresh() (cron_refresh_metrics
-- every 10 min + at the start of the hourly compute-limit cron). Enforcement
-- (ComputeLimitCron) deliberately keeps reading live — this rollup only backs
-- read-only display, so bounded staleness is acceptable.
--
-- Grain is per-run: per-user and instance-wide figures are cheap sums over the
-- ~O(runs) rows. n_run_sessions/last_access come from survey_run_sessions;
-- the execution_time columns count only rows where execution_time IS NOT NULL
-- (same definition the dashboards use). month_execution_time is valid only for
-- month_key (YYYY-MM); readers treat a stale month_key as 0 for "this month".
CREATE TABLE IF NOT EXISTS `survey_run_metrics` (
  `run_id` INT(10) UNSIGNED NOT NULL,
  `n_run_sessions` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_access` DATETIME NULL DEFAULT NULL,
  `n_exec_sessions` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `total_execution_time` DECIMAL(16,3) NOT NULL DEFAULT 0.000,
  `month_execution_time` DECIMAL(16,3) NOT NULL DEFAULT 0.000,
  `month_key` CHAR(7) NOT NULL DEFAULT '',
  `max_execution_time` DECIMAL(12,3) NULL DEFAULT NULL,
  `last_activity` DATETIME NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`run_id`),
  KEY `idx_total_time` (`total_execution_time`),
  CONSTRAINT `fk_run_metrics_run` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial seed so reads work immediately after deploy (the same statement
-- RunMetrics::refresh() issues, minus the bound month params).
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
    max_execution_time = VALUES(max_execution_time), last_activity = VALUES(last_activity);
