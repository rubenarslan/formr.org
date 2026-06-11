-- Record the layout mode (default/solo) per response, for measurement
-- equivalence. Co-located (scrolling/default) vs isolated (paged/solo) item
-- presentation changes inter-item correlations and possibly factor structure,
-- so the mode each response was collected under must stay recoverable even if
-- the study's `layout` is later flipped. Stamped at first render in
-- UnitSession::createSurveyStudyRecord from SurveyStudy.layout.
ALTER TABLE `survey_unit_sessions`
    ADD COLUMN `layout` VARCHAR(16) DEFAULT NULL AFTER `study_iteration`;
