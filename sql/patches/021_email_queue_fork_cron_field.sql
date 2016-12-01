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


ALTER TABLE `survey_email_queue`
  ADD CONSTRAINT `survey_email_queue_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `survey_email_accounts` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION;

ALTER TABLE  `survey_email_log` ADD  `sent` TINYINT( 1 ) NOT NULL DEFAULT  '1';

ALTER TABLE `survey_runs` ADD `cron_fork` TINYINT UNSIGNED NOT NULL DEFAULT '1' ;
