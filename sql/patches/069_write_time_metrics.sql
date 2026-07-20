-- Write-time metrics accounting (see documentation/agent_doc/write_time_metrics_plan.md).
-- Per-study response/duration rollup (getResultCount SQ-11 + the geometric-mean
-- duration that replaces the removed median SQ-10), maintained write-time at
-- survey start/complete; plus per-run count columns for SQ-06/14/37 that the
-- nightly reconcile fills. Schema only — seeding is done by RunMetrics::reconcile()
-- (study metrics aggregate per-study dynamic results tables, which static SQL
-- can't enumerate).

-- Per-study (SurveyStudy) response counts + geometric-mean duration accumulator.
-- begun/finished/testers/real_users mirror SurveyStudy::getResultCount's SUMs.
-- Geometric mean of completion time = EXP(sum_log_duration / n_durations); kept
-- as a running Σln + count so it is incrementally maintainable (a median is not).
-- No exclusion: every completion contributes LN(GREATEST(seconds, 1)) so a
-- sub-second/degenerate completion floors to LN(1)=0 rather than being dropped.
CREATE TABLE IF NOT EXISTS `survey_study_metrics` (
  `study_id` INT(10) UNSIGNED NOT NULL,
  `finished` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `testers` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `real_users` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `sum_log_duration` DOUBLE NOT NULL DEFAULT 0,
  `n_durations` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`study_id`),
  CONSTRAINT `fk_study_metrics_study` FOREIGN KEY (`study_id`) REFERENCES `survey_studies` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-run count columns backing the admin pagination counts SQ-06 (user detail
-- unit sessions), SQ-14 (push log), SQ-37 (email log). Reconcile-maintained;
-- their reads fall back to a live COUNT when the metrics row is absent.
ALTER TABLE `survey_run_metrics`
  ADD COLUMN `n_unit_sessions` INT(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `n_run_sessions`,
  ADD COLUMN `n_push_logs` INT(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `n_unit_sessions`,
  ADD COLUMN `n_email_logs` INT(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `n_push_logs`;
