CREATE TABLE IF NOT EXISTS `survey_email_queue` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `subject` varchar(355) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `message` text CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `recipient` varchar(100) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `created` datetime NOT NULL,
  `meta` text CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `account_id` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CALL formr_add_foreign_key_if_not_exists('survey_email_queue', 'survey_email_queue_ibfk_1', 'account_id', 'survey_email_accounts', 'id', 'CASCADE', 'NO ACTION');
ALTER TABLE `survey_email_log` ADD COLUMN IF NOT EXISTS `sent` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE `survey_runs` ADD COLUMN IF NOT EXISTS `cron_fork` TINYINT UNSIGNED NOT NULL DEFAULT 1;
