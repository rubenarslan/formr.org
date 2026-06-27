-- Issue #608: record how long each unit session spent executing (incl.
-- OpenCPU/R API calls) so study admins and superadmins can see which
-- studies are resource-hungry and where recurrent slow/broken code lives.
--
-- Cumulative wall-clock seconds spent inside UnitSession::execute().
-- Surveys/Pauses/etc. execute multiple times; each pass adds to this
-- value (see issue's "simply add to runtime" open question). NULL means
-- "never measured" (rows created before this feature shipped), which the
-- usage dashboards exclude from their aggregates.
ALTER TABLE `survey_unit_sessions`
    ADD COLUMN `execution_time` DECIMAL(12,3) UNSIGNED NULL DEFAULT NULL
    COMMENT 'Cumulative wall-clock seconds in UnitSession::execute(), incl. OpenCPU (issue #608)';
