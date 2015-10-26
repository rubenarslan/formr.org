SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;


CREATE TABLE IF NOT EXISTS `oauth_access_tokens` (
  `access_token` varchar(40) NOT NULL,
  `client_id` varchar(80) NOT NULL,
  `user_id` varchar(255) DEFAULT NULL,
  `expires` timestamp NOT NULL,
  `scope` varchar(2000) DEFAULT NULL,
  PRIMARY KEY (`access_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `oauth_authorization_codes` (
  `authorization_code` varchar(40) NOT NULL,
  `client_id` varchar(80) NOT NULL,
  `user_id` varchar(255) DEFAULT NULL,
  `redirect_uri` varchar(2000) DEFAULT NULL,
  `expires` timestamp NOT NULL,
  `scope` varchar(2000) DEFAULT NULL,
  PRIMARY KEY (`authorization_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `oauth_clients` (
  `client_id` varchar(80) NOT NULL,
  `client_secret` varchar(80) NOT NULL,
  `redirect_uri` varchar(2000) NOT NULL,
  `grant_types` varchar(80) DEFAULT NULL,
  `scope` varchar(100) DEFAULT NULL,
  `user_id` varchar(80) DEFAULT NULL,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `oauth_jwt` (
  `client_id` varchar(80) NOT NULL,
  `subject` varchar(80) DEFAULT NULL,
  `public_key` varchar(2000) DEFAULT NULL,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `oauth_refresh_tokens` (
  `refresh_token` varchar(40) NOT NULL,
  `client_id` varchar(80) NOT NULL,
  `user_id` varchar(255) DEFAULT NULL,
  `expires` timestamp NOT NULL,
  `scope` varchar(2000) DEFAULT NULL,
  PRIMARY KEY (`refresh_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `oauth_scopes` (
  `scope` text,
  `is_default` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `oauth_users` (
  `username` varchar(255) NOT NULL,
  `password` varchar(2000) DEFAULT NULL,
  `first_name` varchar(255) DEFAULT NULL,
  `last_name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `shuffle` (
  `session_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `created` datetime DEFAULT NULL,
  `group` smallint(5) unsigned DEFAULT NULL,
  PRIMARY KEY (`session_id`),
  KEY `fk_survey_results_survey_unit_sessions1_idx` (`session_id`),
  KEY `fk_survey_reports_survey_units1_idx` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_branches` (
  `id` int(10) unsigned NOT NULL,
  `condition` text,
  `if_true` smallint(6) DEFAULT NULL,
  `automatically_jump` tinyint(1) DEFAULT '1',
  `automatically_go_on` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `fk_survey_branch_survey_units1_idx` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_cron_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `run_id` int(10) unsigned NOT NULL,
  `created` datetime DEFAULT NULL,
  `ended` datetime DEFAULT NULL,
  `sessions` int(10) unsigned DEFAULT NULL,
  `skipforwards` int(10) unsigned DEFAULT NULL,
  `skipbackwards` int(10) unsigned DEFAULT NULL,
  `pauses` int(10) unsigned DEFAULT NULL,
  `emails` int(10) unsigned DEFAULT NULL,
  `shuffles` int(10) unsigned DEFAULT NULL,
  `errors` int(10) unsigned DEFAULT NULL,
  `warnings` int(10) unsigned DEFAULT NULL,
  `notices` int(10) unsigned DEFAULT NULL,
  `message` text,
  PRIMARY KEY (`id`),
  KEY `fk_survey_cron_log_survey_runs1_idx` (`run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_emails` (
  `id` int(10) unsigned NOT NULL,
  `account_id` int(10) unsigned DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `recipient_field` varchar(255) DEFAULT NULL,
  `body` mediumtext,
  `body_parsed` mediumtext,
  `html` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_survey_emails_survey_units1_idx` (`id`),
  KEY `fk_survey_emails_survey_email_accounts1_idx` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_email_accounts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `from` varchar(255) DEFAULT NULL,
  `from_name` varchar(255) DEFAULT NULL,
  `host` varchar(255) DEFAULT NULL,
  `port` smallint(6) DEFAULT NULL,
  `tls` tinyint(4) DEFAULT NULL,
  `username` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_survey_emails_survey_users1_idx` (`user_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_email_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` int(10) unsigned DEFAULT NULL,
  `email_id` int(10) unsigned DEFAULT NULL,
  `created` datetime NOT NULL,
  `recipient` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_survey_email_log_survey_emails1_idx` (`email_id`),
  KEY `fk_survey_email_log_survey_unit_sessions1_idx` (`session_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_externals` (
  `id` int(10) unsigned NOT NULL,
  `address` text,
  `api_end` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `fk_survey_forks_survey_run_items1_idx` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_items` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `study_id` int(10) unsigned NOT NULL,
  `type` varchar(100) NOT NULL,
  `choice_list` varchar(255) DEFAULT NULL,
  `type_options` varchar(255) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `label` text,
  `label_parsed` mediumtext,
  `optional` tinyint(4) DEFAULT NULL,
  `class` varchar(255) DEFAULT NULL,
  `showif` text,
  `value` text,
  `block_order` varchar(4) DEFAULT NULL,
  `item_order` smallint(6) DEFAULT NULL,
  `order` varchar(4) DEFAULT NULL,
  `post_process` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `study_item` (`study_id`,`name`),
  KEY `fk_survey_items_survey_studies1_idx` (`study_id`),
  KEY `type` (`study_id`,`type`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_items_display` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(10) unsigned NOT NULL,
  `session_id` int(10) unsigned NOT NULL,
  `answer` text,
  `created` datetime DEFAULT NULL,
  `answered` datetime DEFAULT NULL,
  `answered_relative` double DEFAULT NULL,
  `displaycount` smallint(5) unsigned DEFAULT NULL,
  `display_order` mediumint(8) unsigned DEFAULT NULL,
  `hidden` tinyint(1) DEFAULT NULL,
  `saved` datetime DEFAULT NULL,
  `shown` datetime DEFAULT NULL,
  `shown_relative` double DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_item_views` (`session_id`,`item_id`),
  KEY `id_idx` (`item_id`),
  KEY `answered` (`session_id`,`saved`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_item_choices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `study_id` int(10) unsigned NOT NULL,
  `list_name` varchar(255) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `label` text,
  `label_parsed` mediumtext,
  PRIMARY KEY (`id`),
  KEY `fk_survey_item_choices_survey_studies1_idx` (`study_id`),
  KEY `listname` (`list_name`),
  KEY `list_name` (`list_name`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_pages` (
  `id` int(10) unsigned NOT NULL,
  `body` mediumtext,
  `body_parsed` mediumtext,
  `title` varchar(255) DEFAULT NULL,
  `end` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `fk_survey_feedback_survey_units1_idx` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_pauses` (
  `id` int(10) unsigned NOT NULL,
  `wait_until_time` time DEFAULT NULL,
  `wait_until_date` date DEFAULT NULL,
  `wait_minutes` int(11) DEFAULT NULL,
  `relative_to` text,
  `body` mediumtext,
  `body_parsed` mediumtext,
  PRIMARY KEY (`id`),
  KEY `fk_survey_breaks_survey_run_items1_idx` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_reports` (
  `session_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `created` datetime DEFAULT NULL,
  `last_viewed` datetime DEFAULT NULL,
  `opencpu_url` varchar(400) DEFAULT NULL,
  PRIMARY KEY (`session_id`),
  KEY `fk_survey_results_survey_unit_sessions1_idx` (`session_id`),
  KEY `fk_survey_reports_survey_units1_idx` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_results` (
  `session_id` int(10) unsigned NOT NULL,
  `study_id` int(10) unsigned NOT NULL,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `ended` datetime DEFAULT NULL,
  PRIMARY KEY (`session_id`),
  KEY `fk_survey_results_survey_unit_sessions1_idx` (`session_id`),
  KEY `fk_survey_results_survey_studies1_idx` (`study_id`),
  KEY `ending` (`session_id`,`study_id`,`ended`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_runs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `name` varchar(45) DEFAULT NULL,
  `api_secret_hash` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin DEFAULT NULL,
  `cron_active` tinyint(1) DEFAULT '0',
  `public` tinyint(4) DEFAULT '0',
  `locked` tinyint(1) DEFAULT '0',
  `reminder_email` int(10) unsigned DEFAULT NULL,
  `service_message` int(10) unsigned DEFAULT NULL,
  `overview_script` int(10) unsigned DEFAULT NULL,
  `deactivated_page` int(10) unsigned DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` varchar(1000) DEFAULT NULL,
  `description_parsed` text,
  `public_blurb` text,
  `public_blurb_parsed` text,
  `header_image_path` varchar(255) DEFAULT NULL,
  `footer_text` text,
  `footer_text_parsed` text,
  `custom_css_path` varchar(255) DEFAULT NULL,
  `custom_js_path` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_runs_survey_users1_idx` (`user_id`),
  KEY `fk_survey_runs_survey_units1_idx` (`reminder_email`),
  KEY `fk_survey_runs_survey_units2_idx` (`service_message`),
  KEY `fk_survey_runs_survey_units3_idx` (`overview_script`),
  KEY `fk_survey_runs_survey_units4_idx` (`deactivated_page`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_run_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `run_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `session` char(64) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `created` datetime DEFAULT NULL,
  `ended` datetime DEFAULT NULL,
  `last_access` datetime DEFAULT NULL,
  `position` smallint(6) DEFAULT NULL,
  `current_unit_id` int(10) unsigned DEFAULT NULL,
  `deactivated` tinyint(1) DEFAULT '0',
  `no_email` int(11) DEFAULT NULL,
  `testing` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `run_session` (`session`,`run_id`),
  UNIQUE KEY `run_user` (`user_id`,`run_id`),
  KEY `fk_survey_run_sessions_survey_runs1_idx` (`run_id`),
  KEY `fk_survey_run_sessions_survey_users1_idx` (`user_id`),
  KEY `fk_survey_run_sessions_survey_units1_idx` (`current_unit_id`),
  KEY `position` (`position`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_run_settings` (
  `run_session_id` int(10) unsigned NOT NULL,
  `settings` text NOT NULL,
  PRIMARY KEY (`run_session_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_run_units` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned DEFAULT NULL,
  `position` smallint(6) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`,`run_id`),
  KEY `fk_survey_run_data_survey_runs1_idx` (`run_id`),
  KEY `fk_survey_run_data_survey_run_items1_idx` (`unit_id`),
  KEY `position_run` (`run_id`,`position`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_shuffles` (
  `id` int(10) unsigned NOT NULL,
  `groups` smallint(5) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_survey_branch_survey_units1_idx` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_studies` (
  `id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `results_table` varchar(64) DEFAULT NULL,
  `valid` tinyint(1) DEFAULT NULL,
  `maximum_number_displayed` smallint(5) unsigned DEFAULT NULL,
  `displayed_percentage_maximum` tinyint(3) unsigned DEFAULT NULL,
  `add_percentage_points` tinyint(4) DEFAULT NULL,
  `expire_after` int(10) unsigned DEFAULT NULL,
  `enable_instant_validation` tinyint(1) DEFAULT '1',
  `original_file` varchar(50) DEFAULT NULL,
  `google_file_id` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `results_table_UNIQUE` (`results_table`),
  UNIQUE KEY `name_by_user` (`user_id`,`name`),
  KEY `fk_survey_studies_survey_users_idx` (`user_id`),
  KEY `fk_survey_studies_run_items1_idx` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_text_messages` (
  `id` int(10) unsigned NOT NULL,
  `account_id` int(10) unsigned DEFAULT NULL,
  `recipient_field` varchar(255) DEFAULT NULL,
  `body` mediumtext,
  PRIMARY KEY (`id`),
  KEY `fk_survey_emails_survey_units1_idx` (`id`),
  KEY `fk_survey_emails_survey_email_accounts1_idx` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_units` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `type` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `type` (`type`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_unit_sessions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `unit_id` int(10) unsigned NOT NULL,
  `run_session_id` int(11) DEFAULT NULL,
  `created` datetime NOT NULL,
  `ended` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `session_uq` (`created`,`run_session_id`,`unit_id`),
  KEY `fk_survey_sessions_survey_units1_idx` (`unit_id`),
  KEY `fk_survey_unit_sessions_survey_run_sessions1_idx` (`run_session_id`),
  KEY `ended` (`ended`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_uploaded_files` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int(10) unsigned NOT NULL,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `original_file_name` varchar(255) DEFAULT NULL,
  `new_file_path` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`,`run_id`),
  UNIQUE KEY `unique` (`run_id`,`original_file_name`),
  KEY `fk_survey_uploaded_files_survey_runs1_idx` (`run_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `survey_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_code` char(64) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `admin` tinyint(1) DEFAULT '0',
  `email` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `email_verification_hash` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin DEFAULT NULL,
  `email_verified` tinyint(1) DEFAULT '0',
  `reset_token_hash` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin DEFAULT NULL,
  `reset_token_expiry` datetime DEFAULT NULL,
  `mobile_number` varchar(30) DEFAULT NULL,
  `mobile_verification_hash` varchar(255) DEFAULT NULL,
  `mobile_verified` tinyint(1) DEFAULT NULL,
  `referrer_code` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_code_UNIQUE` (`user_code`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;


ALTER TABLE `shuffle`
  ADD CONSTRAINT `fk_unit_sessions_shuffle` FOREIGN KEY (`session_id`) REFERENCES `survey_unit_sessions` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  ADD CONSTRAINT `fk_unit_shuffle` FOREIGN KEY (`unit_id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION;

ALTER TABLE `survey_branches`
  ADD CONSTRAINT `fk_branch_unit` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION;

ALTER TABLE `survey_cron_log`
  ADD CONSTRAINT `fk_survey_cron_log_survey_runs1` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION;

ALTER TABLE `survey_emails`
  ADD CONSTRAINT `fk_email_acc` FOREIGN KEY (`account_id`) REFERENCES `survey_email_accounts` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  ADD CONSTRAINT `fk_email_unit` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION;

ALTER TABLE `survey_email_accounts`
  ADD CONSTRAINT `fk_email_user` FOREIGN KEY (`user_id`) REFERENCES `survey_users` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION;

ALTER TABLE `survey_email_log`
  ADD CONSTRAINT `fk_survey_email_log_survey_emails1` FOREIGN KEY (`email_id`) REFERENCES `survey_emails` (`id`) ON DELETE SET NULL ON UPDATE NO ACTION,
  ADD CONSTRAINT `fk_survey_email_log_survey_unit_sessions1` FOREIGN KEY (`session_id`) REFERENCES `survey_unit_sessions` (`id`) ON DELETE SET NULL ON UPDATE NO ACTION;

ALTER TABLE `survey_externals`
  ADD CONSTRAINT `fk_external_unit` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION;

ALTER TABLE `survey_items`
  ADD CONSTRAINT `fk_survey_items_survey_studies1` FOREIGN KEY (`study_id`) REFERENCES `survey_studies` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION;

ALTER TABLE `survey_items_display`
  ADD CONSTRAINT `itemid` FOREIGN KEY (`item_id`) REFERENCES `survey_items` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  ADD CONSTRAINT `sessionidx` FOREIGN KEY (`session_id`) REFERENCES `survey_unit_sessions` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION;

ALTER TABLE `survey_item_choices`
  ADD CONSTRAINT `fk_survey_item_choices_survey_studies1` FOREIGN KEY (`study_id`) REFERENCES `survey_studies` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION;

ALTER TABLE `survey_pages`
  ADD CONSTRAINT `fk_page_unit` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION;

ALTER TABLE `survey_pauses`
  ADD CONSTRAINT `fk_survey_breaks_survey_run_items1` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION;

ALTER TABLE `survey_reports`
  ADD CONSTRAINT `fk_survey_reports_survey_units1` FOREIGN KEY (`unit_id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  ADD CONSTRAINT `fk_survey_results_survey_unit_sessions10` FOREIGN KEY (`session_id`) REFERENCES `survey_unit_sessions` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION;

ALTER TABLE `survey_results`
  ADD CONSTRAINT `fk_survey_results_survey_studies1` FOREIGN KEY (`study_id`) REFERENCES `survey_studies` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  ADD CONSTRAINT `fk_survey_results_survey_unit_sessions1` FOREIGN KEY (`session_id`) REFERENCES `survey_unit_sessions` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION;

ALTER TABLE `survey_runs`
  ADD CONSTRAINT `fk_runs_survey_users1` FOREIGN KEY (`user_id`) REFERENCES `survey_users` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  ADD CONSTRAINT `fk_survey_runs_survey_units1` FOREIGN KEY (`reminder_email`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  ADD CONSTRAINT `fk_survey_runs_survey_units2` FOREIGN KEY (`service_message`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  ADD CONSTRAINT `fk_survey_runs_survey_units3` FOREIGN KEY (`overview_script`) REFERENCES `survey_units` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  ADD CONSTRAINT `fk_survey_runs_survey_units4` FOREIGN KEY (`deactivated_page`) REFERENCES `survey_units` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION;

ALTER TABLE `survey_run_sessions`
  ADD CONSTRAINT `fk_survey_run_sessions_survey_runs1` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  ADD CONSTRAINT `fk_survey_run_sessions_survey_units1` FOREIGN KEY (`current_unit_id`) REFERENCES `survey_units` (`id`) ON DELETE SET NULL ON UPDATE NO ACTION,
  ADD CONSTRAINT `fk_survey_run_sessions_survey_users1` FOREIGN KEY (`user_id`) REFERENCES `survey_users` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION;

ALTER TABLE `survey_run_units`
  ADD CONSTRAINT `fk_suru` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  ADD CONSTRAINT `fk_suru_it` FOREIGN KEY (`unit_id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION;

ALTER TABLE `survey_shuffles`
  ADD CONSTRAINT `fk_shuffle_unit` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION;

ALTER TABLE `survey_studies`
  ADD CONSTRAINT `fk_study_unit` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  ADD CONSTRAINT `fk_survey_studies_survey_users` FOREIGN KEY (`user_id`) REFERENCES `survey_users` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION;

ALTER TABLE `survey_text_messages`
  ADD CONSTRAINT `fk_email_acc0` FOREIGN KEY (`account_id`) REFERENCES `survey_email_accounts` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  ADD CONSTRAINT `fk_email_unit0` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION;

ALTER TABLE `survey_unit_sessions`
  ADD CONSTRAINT `fk_survey_sessions_survey_units1` FOREIGN KEY (`unit_id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  ADD CONSTRAINT `fk_survey_unit_sessions_survey_run_sessions1` FOREIGN KEY (`run_session_id`) REFERENCES `survey_run_sessions` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION;

ALTER TABLE `survey_uploaded_files`
  ADD CONSTRAINT `fk_survey_uploaded_files_survey_runs1` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
