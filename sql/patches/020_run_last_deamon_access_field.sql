ALTER TABLE `survey_runs` ADD COLUMN IF NOT EXISTS `last_deamon_access` INT UNSIGNED NULL DEFAULT '0';
CREATE INDEX IF NOT EXISTS `last_deamon_access` ON `survey_runs` (`last_deamon_access`);
ALTER TABLE `survey_emails` ADD COLUMN IF NOT EXISTS `cron_only` TINYINT UNSIGNED NOT NULL DEFAULT '0';
