ALTER TABLE `survey_pauses` CHANGE `wait_minutes` `wait_minutes` decimal(13,2) DEFAULT NULL;
ALTER TABLE `survey_unit_sessions` ADD COLUMN IF NOT EXISTS `expires` DATETIME NULL DEFAULT NULL;
ALTER TABLE `survey_unit_sessions` ADD COLUMN IF NOT EXISTS `queued` TINYINT NOT NULL DEFAULT '0';
ALTER TABLE `survey_unit_sessions` ADD COLUMN IF NOT EXISTS `result` VARCHAR(40) NULL;
ALTER TABLE `survey_unit_sessions` ADD COLUMN IF NOT EXISTS `result_log` TEXT NULL;
