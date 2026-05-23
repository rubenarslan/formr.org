-- Long-form source of truth for the per-study "Nth participant" counter
-- that today lives only on the wide per-study results table as
-- `iteration` (INT AUTO_INCREMENT UNIQUE). Wide-table dual-write is now
-- optional ($settings['form_v2_dual_write_results']) — but iteration
-- values are load-bearing for researcher analyses (block randomization,
-- "Nth participant" calculations) and must remain stable.
--
-- Two columns:
--
-- 1. survey_unit_sessions.study_iteration — the per-session iteration
--    value. NULL for non-Survey/Form unit-sessions and for any row
--    backfilled-but-found-with-no-corresponding-wide-row. Populated at
--    session-create time by UnitSession::createSurveyStudyRecord.
--
-- 2. survey_studies.last_iteration — atomic per-study counter.
--    Allocation pattern uses MySQL's LAST_INSERT_ID() trick to make
--    increment + read a single atomic statement, avoiding the
--    classic SELECT MAX + INSERT race:
--
--       UPDATE survey_studies
--         SET last_iteration = LAST_INSERT_ID(last_iteration + 1)
--         WHERE id = :study_id;
--       SELECT LAST_INSERT_ID();   -- yields the new iteration value
--
--    Concurrent allocations serialize on the survey_studies row lock.
--    Counter gaps from rolled-back inserts are tolerated (mirrors
--    AUTO_INCREMENT behavior).
--
-- Existing wide-table data is backfilled into both columns by
-- bin/backfill_study_iteration.php. The backfill is idempotent; safe
-- to re-run.
--
-- Caveat: this patch only changes the iteration source. Other wide-
-- table readers (UnitSession::getRunData for the OpenCPU R overlay,
-- SurveyStudy::getResultCount for admin stats, getAverageTimeItTakes,
-- run-backup TSV) still rely on the wide table being populated.
-- Turning off dual-write today disables export-via-pivot only;
-- migrating those readers is the next slice.

ALTER TABLE `survey_unit_sessions`
    ADD COLUMN `study_iteration` INT UNSIGNED NULL DEFAULT NULL AFTER `iteration`,
    ADD KEY `idx_study_iteration` (`study_iteration`);

ALTER TABLE `survey_studies`
    ADD COLUMN `last_iteration` INT UNSIGNED NOT NULL DEFAULT 0;
