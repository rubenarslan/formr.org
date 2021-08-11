ALTER TABLE `survey_email_log` ADD COLUMN `account_id` INT(10) unsigned;
ALTER TABLE `survey_email_log` ADD COLUMN `subject` VARCHAR(355);
ALTER TABLE `survey_email_log` ADD COLUMN `message` TEXT;
ALTER TABLE `survey_email_log` ADD COLUMN `meta` TEXT;
ALTER TABLE `survey_email_log` ADD COLUMN `status` TINYINT(1);
ALTER TABLE `survey_email_log` DROP COLUMN `sent`;
ALTER TABLE `survey_email_log` ADD COLUMN `sent` DATETIME;
CREATE INDEX `account_status` ON survey_email_log (`account_id`, `status`);

ALTER TABLE `survey_email_accounts` 
	ADD `status` TINYINT(1) DEFAULT 1;

DROP TABLE IF EXISTS `survey_email_queue`;