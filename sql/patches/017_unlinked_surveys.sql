ALTER TABLE `survey_studies` ADD COLUMN IF NOT EXISTS `unlinked` tinyint(1) NULL DEFAULT '0';
