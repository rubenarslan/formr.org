ALTER TABLE `survey_runs` ADD `last_deamon_access` INT UNSIGNED NULL DEFAULT '0' , ADD INDEX (`last_deamon_access`);
