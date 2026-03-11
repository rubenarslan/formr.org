-- Rename column and drop FK (idempotent)
DELIMITER //
DROP PROCEDURE IF EXISTS _patch035_rename//
CREATE PROCEDURE _patch035_rename()
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='survey_run_sessions' AND COLUMN_NAME='current_unit_id') THEN
    ALTER TABLE survey_run_sessions RENAME COLUMN current_unit_id TO current_unit_session_id;
  END IF;
END//
DELIMITER ;
CALL _patch035_rename();
DROP PROCEDURE IF EXISTS _patch035_rename;

CALL formr_drop_foreign_key_if_exists('survey_run_sessions', 'fk_survey_run_sessions_survey_units1');

UPDATE `survey_run_sessions`
  LEFT JOIN `survey_unit_sessions` m ON m.run_session_id = `survey_run_sessions`.id
  LEFT JOIN `survey_unit_sessions` b ON m.run_session_id = b.run_session_id AND m.id < b.id
SET `survey_run_sessions`.`current_unit_session_id` = m.id
WHERE m.run_session_id IS NOT NULL AND b.id IS NULL;

DROP TABLE IF EXISTS `survey_sessions_queue`;
