ALTER TABLE `survey_pauses` CHANGE `wait_minutes` `wait_minutes` decimal(13,2) DEFAULT NULL;
ALTER TABLE `survey_unit_sessions` ADD `expires` DATETIME NULL DEFAULT NULL AFTER `created`, ADD `queued` TINYINT NOT NULL DEFAULT '0' AFTER `expires`, ADD `result` VARCHAR(40) NULL AFTER `queued`, ADD `result_log` TEXT NULL AFTER `result`;
