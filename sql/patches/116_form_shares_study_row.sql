-- SQL Patch 116: a Form unit shares its study's survey_units row
--
-- A Survey unit IS its study: survey_studies.id is an FK onto the study's
-- survey_units row, and every placement's survey_run_units.unit_id is the
-- study id. Form units now follow the same pattern instead of keeping a row
-- of their own that named the study in form_study_id (patch 101). The study
-- row's type follows its engine: 'Form' while rendering_mode = 'v2',
-- 'Survey' otherwise (SurveyStudy::save).
--
-- Placements and unit sessions of every linked Form row move to the study's
-- row, the linked Form rows go, and so does the column. A Form row that names
-- no existing study stays as it is: an unlinked Form, like one just added to
-- a run. Placements are matched by run_unit_id afterwards, so two Forms of
-- the same study in one run stay apart.

UPDATE `survey_run_units` `ru`
  JOIN `survey_units` `f` ON `f`.`id` = `ru`.`unit_id` AND `f`.`type` = 'Form'
  JOIN `survey_studies` `st` ON `st`.`id` = `f`.`form_study_id`
   SET `ru`.`unit_id` = `st`.`id`;

UPDATE `survey_unit_sessions` `us`
  JOIN `survey_units` `f` ON `f`.`id` = `us`.`unit_id` AND `f`.`type` = 'Form'
  JOIN `survey_studies` `st` ON `st`.`id` = `f`.`form_study_id`
   SET `us`.`unit_id` = `st`.`id`;

UPDATE `survey_units` `u`
  JOIN `survey_studies` `st` ON `st`.`id` = `u`.`id`
   SET `u`.`type` = IF(`st`.`rendering_mode` = 'v2', 'Form', 'Survey')
 WHERE `u`.`type` IN ('Survey', 'Form');

DELETE `f` FROM `survey_units` `f`
  JOIN `survey_studies` `st` ON `st`.`id` = `f`.`form_study_id`
 WHERE `f`.`type` = 'Form';

ALTER TABLE `survey_units` DROP INDEX IF EXISTS `form_study_id`;
ALTER TABLE `survey_units` DROP COLUMN IF EXISTS `form_study_id`;
