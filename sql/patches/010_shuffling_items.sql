ALTER TABLE `survey_items` ADD COLUMN IF NOT EXISTS `block_order` VARCHAR(4) NULL DEFAULT NULL;
ALTER TABLE `survey_items` ADD COLUMN IF NOT EXISTS `item_order` SMALLINT(6) NULL DEFAULT NULL;
UPDATE `survey_items` SET item_order = CAST(survey_items.`order` AS UNSIGNED) WHERE item_order IS NULL AND survey_items.`order` IS NOT NULL;
ALTER TABLE `survey_items_display` ADD COLUMN IF NOT EXISTS `display_order` MEDIUMINT(8) UNSIGNED NULL DEFAULT NULL;
ALTER TABLE `survey_items_display` ADD COLUMN IF NOT EXISTS `hidden` TINYINT(1) NULL DEFAULT NULL;
