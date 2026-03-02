ALTER TABLE `survey_studies` ADD COLUMN IF NOT EXISTS `expire_after` INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE `survey_studies` ADD COLUMN IF NOT EXISTS `enable_instant_validation` TINYINT(1) NULL DEFAULT 1;
UPDATE `survey_studies` SET `enable_instant_validation` = 0 WHERE `enable_instant_validation` IS NULL;
