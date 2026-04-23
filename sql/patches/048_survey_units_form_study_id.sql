-- SQL Patch 048: Add form_study_id column to survey_units
--
-- form_v2 Phase 1: Form RunUnits need to reference a SurveyStudy without
-- sharing an id with it (the way v1 Survey units do). This column stores that
-- reference, keeping the Form's own survey_units row intact. NULL for all
-- non-Form units.

ALTER TABLE `survey_units`
ADD COLUMN `form_study_id` INT(10) UNSIGNED NULL DEFAULT NULL AFTER `type`,
ADD KEY `form_study_id` (`form_study_id`);
