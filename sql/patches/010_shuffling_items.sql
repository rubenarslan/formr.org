ALTER TABLE `survey_items` 
ADD COLUMN `block_order` VARCHAR(4) NULL DEFAULT NULL AFTER `value`,
ADD COLUMN `item_order` SMALLINT(6) NULL DEFAULT NULL AFTER `block_order`;

UPDATE `survey_items` SET item_order = CAST(survey_items.`order` AS UNSIGNED);

ALTER TABLE `survey_items_display` 
CHANGE COLUMN `displaycount` `displaycount` SMALLINT(5) UNSIGNED NULL DEFAULT NULL ,
ADD COLUMN `display_order` MEDIUMINT(8) UNSIGNED NULL DEFAULT NULL AFTER `displaycount`,
ADD COLUMN `hidden` TINYINT(1) NULL DEFAULT NULL AFTER `display_order`;
