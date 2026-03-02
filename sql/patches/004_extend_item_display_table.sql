-- Extend survey_items_display (idempotent)
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS=0;

ALTER TABLE `survey_items_display` ADD COLUMN IF NOT EXISTS `saved` DATETIME NULL;
-- Migrate answered_time to saved only when answered_time exists (first run)
DELIMITER //
DROP PROCEDURE IF EXISTS _patch004_migrate//
CREATE PROCEDURE _patch004_migrate()
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='survey_items_display' AND COLUMN_NAME='answered_time') THEN
    UPDATE survey_items_display SET saved = answered_time WHERE answered_time IS NOT NULL;
  END IF;
END//
DELIMITER ;
CALL _patch004_migrate();
DROP PROCEDURE IF EXISTS _patch004_migrate;
CALL formr_drop_column_if_exists('survey_items_display', 'answered_time');
CALL formr_drop_column_if_exists('survey_items_display', 'modified');
UPDATE `survey_items_display` SET `answered` = NULL WHERE 1=1;
ALTER TABLE `survey_items_display` ADD COLUMN IF NOT EXISTS `answer` TEXT NULL DEFAULT NULL;
ALTER TABLE `survey_items_display` ADD COLUMN IF NOT EXISTS `shown` DATETIME NULL DEFAULT NULL;
ALTER TABLE `survey_items_display` ADD COLUMN IF NOT EXISTS `shown_relative` DOUBLE NULL DEFAULT NULL;
ALTER TABLE `survey_items_display` ADD COLUMN IF NOT EXISTS `answered_relative` DOUBLE NULL DEFAULT NULL;
CALL formr_drop_index_if_exists('survey_items_display', 'answered');
CREATE INDEX IF NOT EXISTS `answered` ON `survey_items_display` (`session_id`, `saved`);
CALL formr_add_foreign_key_if_not_exists('survey_items_display', 'sessionidx', 'session_id', 'survey_unit_sessions', 'id', 'CASCADE', 'NO ACTION');

SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
