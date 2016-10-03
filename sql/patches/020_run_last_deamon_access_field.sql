ALTER TABLE `survey_runs` ADD `last_deamon_access` INT UNSIGNED NULL DEFAULT '0' , ADD INDEX (`last_deamon_access`);
ALTER TABLE `survey_emails` ADD `cron_only` TINYINT UNSIGNED NOT NULL DEFAULT '0' ;
