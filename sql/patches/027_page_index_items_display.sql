ALTER TABLE `survey_items_display` ADD `page` TINYINT UNSIGNED NULL , ADD INDEX (`page`) ;
ALTER TABLE `survey_studies` ADD `use_paging` TINYINT NOT NULL DEFAULT '0' ;
