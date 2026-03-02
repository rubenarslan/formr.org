ALTER TABLE `survey_items_display` ADD COLUMN IF NOT EXISTS `page` TINYINT UNSIGNED NULL;
CREATE INDEX IF NOT EXISTS `page` ON `survey_items_display` (`page`);
ALTER TABLE `survey_studies` ADD COLUMN IF NOT EXISTS `use_paging` TINYINT NOT NULL DEFAULT '0';
