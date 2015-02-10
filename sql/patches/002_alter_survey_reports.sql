ALTER TABLE `survey_reports` DROP COLUMN `body_knit`;
ALTER TABLE `survey_reports` ADD COLUMN `opencpu_url` VARCHAR(400) NULL DEFAULT NULL;
