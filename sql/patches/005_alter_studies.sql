ALTER TABLE `survey_studies` ADD COLUMN `expire_after` INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE `survey_studies` ADD COLUMN `enable_instant_validation` TINYINT(1) NULL DEFAULT 1;