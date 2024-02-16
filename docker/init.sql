-- CREATE DATABASE formr IF NOT EXISTS DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci;
-- GRANT ALL PRIVILEGES ON formr.* TO formr@localhost IDENTIFIED BY 'mauFJcuf5dhRMQrjj';
-- FLUSH PRIVILEGES;

--
-- Database: `formr`
-- Schema Updated: 25.01.2024
--
SET NAMES utf8mb4;
CREATE DATABASE IF NOT EXISTS formr CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
USE formr;

--
-- Table structure for table `oauth_access_tokens`
--

CREATE TABLE `oauth_access_tokens` (
  `access_token` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `client_id` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expires` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `scope` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`access_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `oauth_authorization_codes`
--

CREATE TABLE `oauth_authorization_codes` (
  `authorization_code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `client_id` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `redirect_uri` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expires` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `scope` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`authorization_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


--
-- Table structure for table `oauth_clients`
--

CREATE TABLE `oauth_clients` (
  `client_id` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `client_secret` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `redirect_uri` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `grant_types` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scope` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `oauth_jwt`
--

CREATE TABLE `oauth_jwt` (
  `client_id` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `subject` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `public_key` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


--
-- Table structure for table `oauth_refresh_tokens`
--

CREATE TABLE `oauth_refresh_tokens` (
  `refresh_token` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `client_id` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expires` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `scope` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`refresh_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `oauth_scopes`
--

CREATE TABLE `oauth_scopes` (
  `scope` mediumtext COLLATE utf8mb4_unicode_ci,
  `is_default` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `oauth_users`
--

CREATE TABLE `oauth_users` (
  `username` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `password` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `osf`
--

CREATE TABLE `osf` (
  `user_id` int(10) unsigned NOT NULL,
  `access_token` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `access_token_expires` int(10) unsigned NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `survey_users`
--

CREATE TABLE `survey_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_code` char(64) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL,
  `first_name` VARCHAR(50) NULL,
  `last_name` VARCHAR(50) NULL,
  `affiliation` VARCHAR(350) NULL,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `admin` tinyint(1) DEFAULT '0',
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verification_hash` varchar(255) CHARACTER SET latin1 COLLATE latin1_bin DEFAULT NULL,
  `email_verified` tinyint(1) DEFAULT '0',
  `reset_token_hash` varchar(255) CHARACTER SET latin1 COLLATE latin1_bin DEFAULT NULL,
  `reset_token_expiry` datetime DEFAULT NULL,
  `mobile_number` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile_verification_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile_verified` tinyint(1) DEFAULT '0',
  `referrer_code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `2fa_code` varchar(16) DEFAULT '',
  `backup_codes` varchar(69) DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_code_UNIQUE` (`user_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `survey_units`
--

CREATE TABLE `survey_units` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `survey_runs`
--

CREATE TABLE `survey_runs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `expiresOn` datetime DEFAULT NULL,
  `name` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `api_secret_hash` varchar(255) CHARACTER SET latin1 COLLATE latin1_bin DEFAULT NULL,
  `cron_active` tinyint(1) DEFAULT '0',
  `public` tinyint(4) DEFAULT '0',
  `locked` tinyint(1) DEFAULT '0',
  `reminder_email` int(10) unsigned DEFAULT NULL,
  `service_message` int(10) unsigned DEFAULT NULL,
  `overview_script` int(10) unsigned DEFAULT NULL,
  `deactivated_page` int(10) unsigned DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description_parsed` mediumtext COLLATE utf8mb4_unicode_ci,
  `public_blurb` mediumtext COLLATE utf8mb4_unicode_ci,
  `public_blurb_parsed` mediumtext COLLATE utf8mb4_unicode_ci,
  `header_image_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `footer_text` mediumtext COLLATE utf8mb4_unicode_ci,
  `footer_text_parsed` mediumtext COLLATE utf8mb4_unicode_ci,
  `privacy` mediumtext COLLATE utf8mb4_unicode_ci,
  `privacy_parsed` mediumtext COLLATE utf8mb4_unicode_ci,
  `tos` mediumtext COLLATE utf8mb4_unicode_ci,
  `tos_parsed` mediumtext COLLATE utf8mb4_unicode_ci,
  `imprint` mediumtext COLLATE utf8mb4_unicode_ci,
  `imprint_parsed` mediumtext COLLATE utf8mb4_unicode_ci,
  `custom_css_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `custom_js_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `osf_project_id` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_deamon_access` int(10) unsigned DEFAULT '0',
  `cron_fork` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `use_material_design` tinyint(1) NOT NULL DEFAULT '0',
  `expire_cookie` INT UNSIGNED NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `fk_runs_survey_users1_idx` (`user_id`),
  KEY `fk_survey_runs_survey_units1_idx` (`reminder_email`),
  KEY `fk_survey_runs_survey_units2_idx` (`service_message`),
  KEY `fk_survey_runs_survey_units3_idx` (`overview_script`),
  KEY `fk_survey_runs_survey_units4_idx` (`deactivated_page`),
  KEY `last_deamon_access` (`last_deamon_access`),
  CONSTRAINT `fk_runs_survey_users1` FOREIGN KEY (`user_id`) REFERENCES `survey_users` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_runs_survey_units1` FOREIGN KEY (`reminder_email`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_runs_survey_units2` FOREIGN KEY (`service_message`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_runs_survey_units3` FOREIGN KEY (`overview_script`) REFERENCES `survey_units` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_runs_survey_units4` FOREIGN KEY (`deactivated_page`) REFERENCES `survey_units` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `survey_run_sessions`
--

CREATE TABLE `survey_run_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `run_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `session` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created` datetime DEFAULT NULL,
  `ended` datetime DEFAULT NULL,
  `last_access` datetime DEFAULT NULL,
  `position` smallint(6) DEFAULT NULL,
  `current_unit_session_id` int(10) unsigned DEFAULT NULL,
  `deactivated` tinyint(1) DEFAULT 0,
  `no_email` int(11) DEFAULT NULL,
  `testing` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `run_session` (`session`,`run_id`),
  UNIQUE KEY `run_user` (`user_id`,`run_id`),
  KEY `fk_survey_run_sessions_survey_runs1_idx` (`run_id`),
  KEY `fk_survey_run_sessions_survey_users1_idx` (`user_id`),
  KEY `fk_survey_run_sessions_survey_units1_idx` (`current_unit_session_id`),
  KEY `position` (`position`),
  CONSTRAINT `fk_survey_run_sessions_survey_runs1` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_run_sessions_survey_users1` FOREIGN KEY (`user_id`) REFERENCES `survey_users` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
--
-- Table structure for table `survey_unit_sessions`
--

CREATE TABLE `survey_unit_sessions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `unit_id` int(10) unsigned NOT NULL,
  `run_session_id` int(11) DEFAULT NULL,
  `created` datetime NOT NULL,
  `expires` datetime DEFAULT NULL,
  `queued` tinyint(3) NOT NULL DEFAULT 0,
  `result` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `result_log` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ended` datetime DEFAULT NULL,
  `expired` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `session_uq` (`created`,`run_session_id`,`unit_id`),
  KEY `fk_survey_sessions_survey_units1_idx` (`unit_id`),
  KEY `fk_survey_unit_sessions_survey_run_sessions1_idx` (`run_session_id`),
  KEY `ended` (`ended`),
  KEY `queued_expires` (`queued`,`expires`),
  KEY `results` (`created`,`result`,`run_session_id`),
  CONSTRAINT `fk_survey_sessions_survey_units1` FOREIGN KEY (`unit_id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_unit_sessions_survey_run_sessions1` FOREIGN KEY (`run_session_id`) REFERENCES `survey_run_sessions` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `shuffle`
--

CREATE TABLE `shuffle` (
  `session_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `created` datetime DEFAULT NULL,
  `group` smallint(5) unsigned DEFAULT NULL,
  PRIMARY KEY (`session_id`),
  KEY `fk_survey_results_survey_unit_sessions1_idx` (`session_id`),
  KEY `fk_survey_reports_survey_units1_idx` (`unit_id`),
  CONSTRAINT `fk_unit_sessions_shuffle` FOREIGN KEY (`session_id`) REFERENCES `survey_unit_sessions` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `fk_unit_shuffle` FOREIGN KEY (`unit_id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `survey_branches`
--

CREATE TABLE `survey_branches` (
  `id` int(10) unsigned NOT NULL,
  `condition` mediumtext COLLATE utf8mb4_unicode_ci,
  `if_true` smallint(6) DEFAULT NULL,
  `automatically_jump` tinyint(1) DEFAULT '1',
  `automatically_go_on` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `fk_survey_branch_survey_units1_idx` (`id`),
  CONSTRAINT `fk_branch_unit` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `survey_email_accounts`
--

CREATE TABLE `survey_email_accounts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `from` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `host` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `port` smallint(6) DEFAULT NULL,
  `tls` tinyint(4) DEFAULT NULL,
  `username` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `auth_key` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `deleted` int(1) NOT NULL DEFAULT 0,
  `status` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_survey_emails_survey_users1_idx` (`user_id`),
  CONSTRAINT `fk_email_user` FOREIGN KEY (`user_id`) REFERENCES `survey_users` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
--
-- Table structure for table `survey_emails`
--

CREATE TABLE `survey_emails` (
  `id` int(10) unsigned NOT NULL,
  `account_id` int(10) unsigned DEFAULT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_field` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` mediumtext COLLATE utf8mb4_unicode_ci,
  `body_parsed` mediumtext COLLATE utf8mb4_unicode_ci,
  `html` tinyint(1) DEFAULT NULL,
  `cron_only` tinyint(3) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `fk_survey_emails_survey_units1_idx` (`id`),
  KEY `fk_survey_emails_survey_email_accounts1_idx` (`account_id`),
  CONSTRAINT `fk_email_acc` FOREIGN KEY (`account_id`) REFERENCES `survey_email_accounts` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `fk_email_unit` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


--
-- Table structure for table `survey_email_log`
--

CREATE TABLE `survey_email_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` int(10) unsigned DEFAULT NULL,
  `email_id` int(10) unsigned DEFAULT NULL,
  `created` datetime NOT NULL,
  `recipient` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_id` int(10) unsigned DEFAULT NULL,
  `subject` varchar(355) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` tinyint(1) DEFAULT NULL,
  `sent` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_survey_email_log_survey_emails1_idx` (`email_id`),
  KEY `fk_survey_email_log_survey_unit_sessions1_idx` (`session_id`),
  KEY `account_status` (`account_id`,`status`),
  CONSTRAINT `fk_survey_email_log_survey_emails1` FOREIGN KEY (`email_id`) REFERENCES `survey_emails` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_email_log_survey_unit_sessions1` FOREIGN KEY (`session_id`) REFERENCES `survey_unit_sessions` (`id`) ON DELETE SET NULL ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `survey_externals`
--

CREATE TABLE `survey_externals` (
  `id` int(10) unsigned NOT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `api_end` tinyint(1) DEFAULT '0',
  `expire_after` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_survey_forks_survey_run_items1_idx` (`id`),
  CONSTRAINT `fk_external_unit` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


--
-- Table structure for table `survey_studies`
--

CREATE TABLE `survey_studies` (
  `id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `results_table` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `valid` tinyint(1) DEFAULT NULL,
  `maximum_number_displayed` smallint(5) unsigned DEFAULT NULL,
  `displayed_percentage_maximum` tinyint(3) unsigned DEFAULT NULL,
  `add_percentage_points` tinyint(4) DEFAULT NULL,
  `expire_after` int(10) unsigned DEFAULT NULL,
  `expire_invitation_after` int(10) unsigned DEFAULT NULL,
  `expire_invitation_grace` int(10) unsigned DEFAULT NULL,
  `enable_instant_validation` tinyint(1) DEFAULT '1',
  `original_file` varchar(225) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `google_file_id` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unlinked` tinyint(1) DEFAULT '0',
  `hide_results` tinyint(4) NOT NULL DEFAULT '0',
  `use_paging` tinyint(4) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_by_user` (`user_id`,`name`),
  KEY `fk_survey_studies_survey_users_idx` (`user_id`),
  KEY `fk_survey_studies_run_items1_idx` (`id`),
  CONSTRAINT `fk_study_unit` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_studies_survey_users` FOREIGN KEY (`user_id`) REFERENCES `survey_users` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `survey_items`
--

CREATE TABLE `survey_items` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `study_id` int(10) unsigned NOT NULL,
  `type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `choice_list` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type_options` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `label` text COLLATE utf8mb4_unicode_ci,
  `label_parsed` mediumtext COLLATE utf8mb4_unicode_ci,
  `optional` tinyint(4) DEFAULT NULL,
  `class` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `showif` mediumtext COLLATE utf8mb4_unicode_ci,
  `value` text COLLATE utf8mb4_unicode_ci,
  `block_order` varchar(4) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_order` smallint(6) DEFAULT NULL,
  `order` int(10) DEFAULT NULL,
  `post_process` mediumtext COLLATE utf8mb4_unicode_ci,
  `page_no` smallint(5) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `study_item` (`study_id`,`name`),
  KEY `fk_survey_items_survey_studies1_idx` (`study_id`),
  KEY `type` (`study_id`,`type`),
  KEY `page_no` (`page_no`),
  CONSTRAINT `fk_survey_items_survey_studies1` FOREIGN KEY (`study_id`) REFERENCES `survey_studies` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `survey_item_choices`
--

CREATE TABLE `survey_item_choices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `study_id` int(10) unsigned NOT NULL,
  `list_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `label` mediumtext COLLATE utf8mb4_unicode_ci,
  `label_parsed` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `fk_survey_item_choices_survey_studies1_idx` (`study_id`),
  KEY `listname` (`list_name`),
  KEY `list_name` (`list_name`),
  KEY `list_name_2` (`list_name`),
  CONSTRAINT `fk_survey_item_choices_survey_studies1` FOREIGN KEY (`study_id`) REFERENCES `survey_studies` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


--
-- Table structure for table `survey_items_display`
--

CREATE TABLE `survey_items_display` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `item_id` int(10) unsigned NOT NULL,
  `session_id` int(10) unsigned NOT NULL,
  `answer` mediumtext COLLATE utf8mb4_unicode_ci,
  `created` datetime DEFAULT NULL,
  `answered` datetime DEFAULT NULL,
  `answered_relative` double DEFAULT NULL,
  `displaycount` smallint(5) unsigned DEFAULT NULL,
  `display_order` mediumint(8) unsigned DEFAULT NULL,
  `hidden` tinyint(1) DEFAULT NULL,
  `saved` datetime DEFAULT NULL,
  `shown` datetime DEFAULT NULL,
  `shown_relative` double DEFAULT NULL,
  `page` tinyint(3) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_item_views` (`session_id`,`item_id`),
  KEY `id_idx` (`item_id`),
  KEY `answered` (`session_id`,`saved`),
  KEY `page` (`page`),
  CONSTRAINT `itemid` FOREIGN KEY (`item_id`) REFERENCES `survey_items` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `sessionidx` FOREIGN KEY (`session_id`) REFERENCES `survey_unit_sessions` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `survey_newsletter`
--

CREATE TABLE `survey_newsletter` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `names` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified` tinyint(1) NOT NULL DEFAULT '0',
  `email_verification_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `survey_pages`
--

CREATE TABLE `survey_pages` (
  `id` int(10) unsigned NOT NULL,
  `body` mediumtext COLLATE utf8mb4_unicode_ci,
  `body_parsed` mediumtext COLLATE utf8mb4_unicode_ci,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `end` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `fk_survey_feedback_survey_units1_idx` (`id`),
  CONSTRAINT `fk_page_unit` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `survey_privacy`
--

CREATE TABLE `survey_privacy` (
  `id` int(10) unsigned NOT NULL,
  `privacy_label` mediumtext COLLATE utf8mb4_unicode_ci,
  `privacy_label_parsed` mediumtext COLLATE utf8mb4_unicode_ci,
  `tos_label` mediumtext COLLATE utf8mb4_unicode_ci,
  `tos_label_parsed` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `fk_survey_privacy_survey_units1_idx` (`id`),
  CONSTRAINT `fk_privacy_unit` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `survey_pauses`
--

CREATE TABLE `survey_pauses` (
  `id` int(10) unsigned NOT NULL,
  `wait_until_time` time DEFAULT NULL,
  `wait_until_date` date DEFAULT NULL,
  `wait_minutes` decimal(13,2) DEFAULT NULL,
  `relative_to` mediumtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` mediumtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body_parsed` mediumtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_survey_breaks_survey_run_items1_idx` (`id`),
  CONSTRAINT `fk_survey_breaks_survey_run_items1` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `survey_reports`
--

CREATE TABLE `survey_reports` (
  `session_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `created` datetime DEFAULT NULL,
  `last_viewed` datetime DEFAULT NULL,
  `opencpu_url` varchar(400) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`session_id`),
  KEY `fk_survey_results_survey_unit_sessions1_idx` (`session_id`),
  KEY `fk_survey_reports_survey_units1_idx` (`unit_id`),
  CONSTRAINT `fk_survey_reports_survey_units1` FOREIGN KEY (`unit_id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_results_survey_unit_sessions10` FOREIGN KEY (`session_id`) REFERENCES `survey_unit_sessions` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `survey_results`
--

CREATE TABLE `survey_results` (
  `session_id` int(10) unsigned NOT NULL,
  `study_id` int(10) unsigned NOT NULL,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `ended` datetime DEFAULT NULL,
  PRIMARY KEY (`session_id`),
  KEY `fk_survey_results_survey_unit_sessions1_idx` (`session_id`),
  KEY `fk_survey_results_survey_studies1_idx` (`study_id`),
  KEY `ending` (`session_id`,`study_id`,`ended`),
  CONSTRAINT `fk_survey_results_survey_studies1` FOREIGN KEY (`study_id`) REFERENCES `survey_studies` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_results_survey_unit_sessions1` FOREIGN KEY (`session_id`) REFERENCES `survey_unit_sessions` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


--
-- Table structure for table `survey_run_settings`
--

CREATE TABLE `survey_run_settings` (
  `run_session_id` int(10) unsigned NOT NULL,
  `settings` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`run_session_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `survey_run_special_units`
--

CREATE TABLE `survey_run_special_units` (
  `id` int(10) unsigned NOT NULL,
  `run_id` int(10) unsigned NOT NULL,
  `type` varchar(25) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(225) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `run_id` (`run_id`),
  KEY `type` (`type`),
  CONSTRAINT `survey_run_special_units_ibfk_1` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `survey_run_special_units_ibfk_2` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `survey_run_units`
--

CREATE TABLE `survey_run_units` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned DEFAULT NULL,
  `position` smallint(6) NOT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`,`run_id`),
  KEY `fk_survey_run_data_survey_runs1_idx` (`run_id`),
  KEY `fk_survey_run_data_survey_run_items1_idx` (`unit_id`),
  KEY `position_run` (`run_id`,`position`),
  CONSTRAINT `fk_suru` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `fk_suru_it` FOREIGN KEY (`unit_id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


--
-- Table structure for table `survey_shuffles`
--

CREATE TABLE `survey_shuffles` (
  `id` int(10) unsigned NOT NULL,
  `groups` smallint(5) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_survey_branch_survey_units1_idx` (`id`),
  CONSTRAINT `fk_shuffle_unit` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `survey_text_messages`
--

CREATE TABLE `survey_text_messages` (
  `id` int(10) unsigned NOT NULL,
  `account_id` int(10) unsigned DEFAULT NULL,
  `recipient_field` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `fk_survey_emails_survey_units1_idx` (`id`),
  KEY `fk_survey_emails_survey_email_accounts1_idx` (`account_id`),
  CONSTRAINT `fk_email_acc0` FOREIGN KEY (`account_id`) REFERENCES `survey_email_accounts` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `fk_email_unit0` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `survey_uploaded_files`
--

CREATE TABLE `survey_uploaded_files` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int(10) unsigned NOT NULL,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `original_file_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`,`run_id`),
  UNIQUE KEY `unique` (`run_id`,`original_file_name`),
  KEY `fk_survey_uploaded_files_survey_runs1_idx` (`run_id`),
  CONSTRAINT `fk_survey_uploaded_files_survey_runs1` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `survey_settings` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting` (`setting`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;