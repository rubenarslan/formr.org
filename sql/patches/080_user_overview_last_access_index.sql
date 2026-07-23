-- Slow-query audit 2026-07, SQ-02/SQ-12 companion:
-- getUserOverviewTable/-Export now ORDER BY last_access DESC (the
-- admin-session pinning moved to PHP); this composite lets the paginated
-- table read in index order and stop at its LIMIT instead of filesorting
-- the whole run.
ALTER TABLE `survey_run_sessions`
  ADD INDEX `idx_run_last_access` (`run_id`, `last_access`);
