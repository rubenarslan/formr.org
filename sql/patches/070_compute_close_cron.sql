-- Issue #608: when the monthly compute limiter closes a user's runs it now also
-- pauses cron (so in-flight sessions stop accruing compute, not just new
-- enrolment). Remember each run's prior cron_active alongside its prior public
-- level (compute_closed_from) so the reopen path can restore both. NULL = the
-- run was not compute-closed (or was closed before this column existed — the
-- reopen COALESCEs to the current cron_active in that case).
ALTER TABLE `survey_runs`
  ADD COLUMN `compute_closed_cron_active` TINYINT NULL DEFAULT NULL
  COMMENT 'Prior cron_active if auto-paused by the monthly compute limiter (issue #608); NULL=not compute-closed'
  AFTER `compute_closed_from`;
