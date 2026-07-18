--
-- Database: `formr`
-- Schema regenerated: 18.07.2026 (full reconcile from migrated DB; drift fix)
--
SET NAMES utf8mb4;
CREATE DATABASE IF NOT EXISTS formr CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
USE formr;
SET FOREIGN_KEY_CHECKS=0;
CREATE TABLE `oauth_access_tokens` (
  `access_token` varchar(64) NOT NULL DEFAULT '',
  `client_id` varchar(80) DEFAULT NULL,
  `user_id` varchar(255) DEFAULT NULL,
  `expires` timestamp NOT NULL DEFAULT current_timestamp(),
  `scope` varchar(2000) DEFAULT NULL,
  `run_ids` varchar(2000) DEFAULT NULL,
  PRIMARY KEY (`access_token`),
  KEY `idx_client_id` (`client_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `oauth_authorization_codes` (
  `authorization_code` varchar(64) NOT NULL DEFAULT '',
  `client_id` varchar(80) DEFAULT NULL,
  `user_id` varchar(255) DEFAULT NULL,
  `redirect_uri` varchar(2000) DEFAULT NULL,
  `expires` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `scope` varchar(2000) DEFAULT NULL,
  PRIMARY KEY (`authorization_code`),
  KEY `idx_client_id` (`client_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `oauth_client_runs` (
  `client_id` varchar(80) NOT NULL,
  `run_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`client_id`,`run_id`),
  KEY `idx_ocr_run_id` (`run_id`),
  CONSTRAINT `fk_ocr_client` FOREIGN KEY (`client_id`) REFERENCES `oauth_clients` (`client_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ocr_run` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `oauth_clients` (
  `client_id` varchar(80) NOT NULL DEFAULT '',
  `client_secret` varchar(80) DEFAULT NULL,
  `redirect_uri` varchar(2000) DEFAULT NULL,
  `grant_types` varchar(80) DEFAULT NULL,
  `scope` varchar(2000) DEFAULT NULL,
  `user_id` varchar(80) DEFAULT NULL,
  `label` varchar(64) NOT NULL,
  PRIMARY KEY (`client_id`),
  UNIQUE KEY `uniq_oauth_clients_user_label` (`user_id`,`label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `oauth_jwt` (
  `client_id` varchar(80) NOT NULL DEFAULT '',
  `subject` varchar(80) DEFAULT NULL,
  `public_key` varchar(2000) DEFAULT NULL,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `oauth_refresh_tokens` (
  `refresh_token` varchar(64) NOT NULL DEFAULT '',
  `client_id` varchar(80) DEFAULT NULL,
  `user_id` varchar(255) DEFAULT NULL,
  `expires` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `scope` varchar(2000) DEFAULT NULL,
  PRIMARY KEY (`refresh_token`),
  KEY `idx_client_id` (`client_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `oauth_scopes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `scope` mediumtext NOT NULL,
  `is_default` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scope` (`scope`(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `oauth_users` (
  `username` varchar(255) NOT NULL DEFAULT '',
  `password` varchar(2000) DEFAULT NULL,
  `first_name` varchar(255) DEFAULT NULL,
  `last_name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `osf` (
  `user_id` int(10) unsigned NOT NULL,
  `access_token` varchar(150) DEFAULT NULL,
  `access_token_expires` int(10) unsigned NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `push_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `unit_session_id` int(10) unsigned NOT NULL,
  `run_id` int(10) unsigned NOT NULL,
  `message` text DEFAULT NULL,
  `status` varchar(20) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `attempt` int(11) NOT NULL DEFAULT 1,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  `idempotency_key` varchar(128) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idemp_push_log` (`idempotency_key`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created`),
  KEY `fk_push_logs_unit_sessions_idx` (`unit_session_id`),
  KEY `idx_run_created` (`run_id`,`created`),
  CONSTRAINT `fk_push_logs_runs` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `fk_push_logs_unit_sessions` FOREIGN KEY (`unit_session_id`) REFERENCES `survey_unit_sessions` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `push_messages` (
  `id` int(10) unsigned NOT NULL,
  `message` text DEFAULT NULL,
  `topic` varchar(255) DEFAULT NULL,
  `priority` varchar(20) NOT NULL DEFAULT 'normal',
  `time_to_live` int(11) NOT NULL DEFAULT 86400,
  `badge_count` int(11) DEFAULT NULL,
  `vibrate` tinyint(1) NOT NULL DEFAULT 1,
  `require_interaction` tinyint(1) NOT NULL DEFAULT 0,
  `renotify` tinyint(1) NOT NULL DEFAULT 0,
  `silent` tinyint(1) NOT NULL DEFAULT 0,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  `modified` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_push_messages_units` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `shuffle` (
  `session_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `created` datetime DEFAULT NULL,
  `group` smallint(5) unsigned DEFAULT NULL,
  PRIMARY KEY (`session_id`),
  KEY `fk_survey_reports_survey_units1_idx` (`unit_id`),
  CONSTRAINT `fk_unit_sessions_shuffle` FOREIGN KEY (`session_id`) REFERENCES `survey_unit_sessions` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `fk_unit_shuffle` FOREIGN KEY (`unit_id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_branches` (
  `id` int(10) unsigned NOT NULL,
  `condition` mediumtext DEFAULT NULL,
  `if_true` smallint(6) DEFAULT NULL,
  `automatically_jump` tinyint(1) DEFAULT 1,
  `automatically_go_on` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_branch_unit` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_email_accounts` (
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
  `reply_to` varchar(255) DEFAULT NULL,
  `auth_key` text NOT NULL,
  `deleted` int(1) NOT NULL DEFAULT 0,
  `status` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_survey_emails_survey_users1_idx` (`user_id`),
  CONSTRAINT `fk_email_user` FOREIGN KEY (`user_id`) REFERENCES `survey_users` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_email_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` int(10) unsigned DEFAULT NULL,
  `email_id` int(10) unsigned DEFAULT NULL,
  `created` datetime NOT NULL,
  `recipient` varchar(255) DEFAULT NULL,
  `account_id` int(10) unsigned DEFAULT NULL,
  `subject` varchar(355) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `meta` text DEFAULT NULL,
  `status` tinyint(1) DEFAULT NULL,
  `sent` datetime DEFAULT NULL,
  `idempotency_key` varchar(128) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idemp_email_log` (`idempotency_key`),
  KEY `fk_survey_email_log_survey_emails1_idx` (`email_id`),
  KEY `fk_survey_email_log_survey_unit_sessions1_idx` (`session_id`),
  KEY `account_status` (`account_id`,`status`),
  KEY `idx_status` (`status`),
  KEY `idx_recipient_status_created` (`recipient`,`status`,`created`),
  CONSTRAINT `fk_survey_email_log_survey_emails1` FOREIGN KEY (`email_id`) REFERENCES `survey_emails` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_email_log_survey_unit_sessions1` FOREIGN KEY (`session_id`) REFERENCES `survey_unit_sessions` (`id`) ON DELETE SET NULL ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_emails` (
  `id` int(10) unsigned NOT NULL,
  `account_id` int(10) unsigned DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `recipient_field` varchar(255) DEFAULT NULL,
  `body` mediumtext DEFAULT NULL,
  `body_parsed` mediumtext DEFAULT NULL,
  `html` tinyint(1) DEFAULT NULL,
  `cron_only` tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `fk_survey_emails_survey_email_accounts1_idx` (`account_id`),
  CONSTRAINT `fk_email_acc` FOREIGN KEY (`account_id`) REFERENCES `survey_email_accounts` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `fk_email_unit` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_externals` (
  `id` int(10) unsigned NOT NULL,
  `address` text DEFAULT NULL,
  `api_end` tinyint(1) DEFAULT 0,
  `expire_after` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_external_unit` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_item_choices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `study_id` int(10) unsigned NOT NULL,
  `list_name` varchar(255) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `label` mediumtext DEFAULT NULL,
  `label_parsed` mediumtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_survey_item_choices_survey_studies1_idx` (`study_id`),
  KEY `list_name` (`list_name`),
  CONSTRAINT `fk_survey_item_choices_survey_studies1` FOREIGN KEY (`study_id`) REFERENCES `survey_studies` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_items` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `study_id` int(10) unsigned NOT NULL,
  `type` varchar(100) DEFAULT NULL,
  `choice_list` varchar(255) DEFAULT NULL,
  `type_options` varchar(255) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `label` mediumtext DEFAULT NULL,
  `label_parsed` mediumtext DEFAULT NULL,
  `optional` tinyint(4) DEFAULT NULL,
  `class` varchar(255) DEFAULT NULL,
  `showif` mediumtext DEFAULT NULL,
  `showif_js` text DEFAULT NULL,
  `value` mediumtext DEFAULT NULL,
  `block_order` varchar(4) DEFAULT NULL,
  `item_order` smallint(6) DEFAULT NULL,
  `order` int(10) DEFAULT NULL,
  `post_process` mediumtext DEFAULT NULL,
  `page_no` smallint(5) unsigned DEFAULT NULL,
  `deleted` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `study_item` (`study_id`,`name`),
  KEY `type` (`study_id`,`type`),
  KEY `page_no` (`page_no`),
  CONSTRAINT `fk_survey_items_survey_studies1` FOREIGN KEY (`study_id`) REFERENCES `survey_studies` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_items_display` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `item_id` int(10) unsigned NOT NULL,
  `session_id` int(10) unsigned NOT NULL,
  `answer` mediumtext DEFAULT NULL,
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
CREATE TABLE `survey_newsletter` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `names` varchar(100) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `email_verification_hash` varchar(255) DEFAULT NULL,
  `date` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int(11) unsigned NOT NULL,
  `session_id` int(11) unsigned NOT NULL,
  `message` text NOT NULL,
  `type` enum('error','warning','info') NOT NULL DEFAULT 'error',
  `created` datetime NOT NULL,
  `recipient_id` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `session_id` (`session_id`),
  KEY `recipient_id` (`recipient_id`),
  KEY `created` (`created`),
  KEY `idx_run_recipient_type` (`run_id`,`recipient_id`,`type`),
  CONSTRAINT `survey_notifications_ibfk_1` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `survey_notifications_ibfk_2` FOREIGN KEY (`session_id`) REFERENCES `survey_unit_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `survey_notifications_ibfk_3` FOREIGN KEY (`recipient_id`) REFERENCES `survey_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `survey_pages` (
  `id` int(10) unsigned NOT NULL,
  `body` mediumtext DEFAULT NULL,
  `body_parsed` mediumtext DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `end` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_page_unit` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_pauses` (
  `id` int(10) unsigned NOT NULL,
  `wait_until_time` time DEFAULT NULL,
  `wait_until_date` date DEFAULT NULL,
  `wait_minutes` decimal(13,2) DEFAULT NULL,
  `relative_to` mediumtext DEFAULT NULL,
  `body` mediumtext DEFAULT NULL,
  `body_parsed` mediumtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_survey_breaks_survey_run_items1` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_reports` (
  `session_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `created` datetime DEFAULT NULL,
  `last_viewed` datetime DEFAULT NULL,
  `opencpu_url` varchar(400) DEFAULT NULL,
  PRIMARY KEY (`session_id`),
  KEY `fk_survey_reports_survey_units1_idx` (`unit_id`),
  CONSTRAINT `fk_survey_reports_survey_units1` FOREIGN KEY (`unit_id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_results_survey_unit_sessions10` FOREIGN KEY (`session_id`) REFERENCES `survey_unit_sessions` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_resource_survey_sizes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `study_id` int(10) unsigned NOT NULL,
  `items_size_kb` decimal(12,2) NOT NULL DEFAULT 0.00,
  `computed_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_study` (`user_id`,`study_id`),
  KEY `study_id` (`study_id`),
  CONSTRAINT `fk_resource_survey_sizes_study` FOREIGN KEY (`study_id`) REFERENCES `survey_studies` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `fk_resource_survey_sizes_user` FOREIGN KEY (`user_id`) REFERENCES `survey_users` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_results` (
  `session_id` int(10) unsigned NOT NULL,
  `study_id` int(10) unsigned NOT NULL,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `ended` datetime DEFAULT NULL,
  PRIMARY KEY (`session_id`),
  KEY `fk_survey_results_survey_studies1_idx` (`study_id`),
  KEY `ending` (`session_id`,`study_id`,`ended`),
  CONSTRAINT `fk_survey_results_survey_studies1` FOREIGN KEY (`study_id`) REFERENCES `survey_studies` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_results_survey_unit_sessions1` FOREIGN KEY (`session_id`) REFERENCES `survey_unit_sessions` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_run_expiry_reminders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int(10) unsigned NOT NULL,
  `reminder_type` varchar(40) NOT NULL,
  `sent_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_survey_run_expiry_reminders_survey_runs1_idx` (`run_id`),
  CONSTRAINT `fk_survey_run_expiry_reminders_survey_runs1` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_run_metrics` (
  `run_id` int(10) unsigned NOT NULL,
  `n_run_sessions` int(10) unsigned NOT NULL DEFAULT 0,
  `n_unit_sessions` int(10) unsigned NOT NULL DEFAULT 0,
  `n_push_logs` int(10) unsigned NOT NULL DEFAULT 0,
  `n_email_logs` int(10) unsigned NOT NULL DEFAULT 0,
  `last_access` datetime DEFAULT NULL,
  `n_exec_sessions` int(10) unsigned NOT NULL DEFAULT 0,
  `total_execution_time` decimal(16,3) NOT NULL DEFAULT 0.000,
  `month_execution_time` decimal(16,3) NOT NULL DEFAULT 0.000,
  `month_key` char(7) NOT NULL DEFAULT '',
  `max_execution_time` decimal(12,3) DEFAULT NULL,
  `last_activity` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`run_id`),
  KEY `idx_total_time` (`total_execution_time`),
  CONSTRAINT `fk_run_metrics_run` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_runs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `name` varchar(45) DEFAULT NULL,
  `api_secret_hash` varchar(255) CHARACTER SET latin1 COLLATE latin1_bin DEFAULT NULL,
  `cron_active` tinyint(1) DEFAULT 0,
  `public` tinyint(4) DEFAULT 0,
  `locked` tinyint(1) DEFAULT 0,
  `reminder_email` int(10) unsigned DEFAULT NULL,
  `service_message` int(10) unsigned DEFAULT NULL,
  `overview_script` int(10) unsigned DEFAULT NULL,
  `deactivated_page` int(10) unsigned DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` varchar(1000) DEFAULT NULL,
  `description_parsed` mediumtext DEFAULT NULL,
  `public_blurb` mediumtext DEFAULT NULL,
  `public_blurb_parsed` mediumtext DEFAULT NULL,
  `header_image_path` varchar(255) DEFAULT NULL,
  `footer_text` mediumtext DEFAULT NULL,
  `footer_text_parsed` mediumtext DEFAULT NULL,
  `privacy` mediumtext DEFAULT NULL,
  `privacy_parsed` mediumtext DEFAULT NULL,
  `tos` mediumtext DEFAULT NULL,
  `tos_parsed` mediumtext DEFAULT NULL,
  `custom_css_path` varchar(255) DEFAULT NULL,
  `custom_js_path` varchar(255) DEFAULT NULL,
  `custom_r_path` varchar(255) DEFAULT NULL,
  `manifest_json_path` varchar(255) DEFAULT NULL,
  `osf_project_id` varchar(20) DEFAULT NULL,
  `last_deamon_access` int(10) unsigned DEFAULT 0,
  `cron_fork` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `use_material_design` tinyint(1) NOT NULL DEFAULT 0,
  `expire_cookie` int(10) unsigned NOT NULL DEFAULT 0,
  `expiresOn` datetime DEFAULT NULL,
  `vapid_public_key` text DEFAULT NULL,
  `vapid_private_key` text DEFAULT NULL,
  `pwa_icon_path` varchar(255) DEFAULT NULL,
  `compute_closed_from` tinyint(4) DEFAULT NULL COMMENT 'Prior public level if auto-closed by the monthly compute limiter (issue #608); NULL=not compute-closed',
  `compute_closed_cron_active` tinyint(4) DEFAULT NULL COMMENT 'Prior cron_active if auto-paused by the monthly compute limiter (issue #608); NULL=not compute-closed',
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
CREATE TABLE `survey_run_secrets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int(10) unsigned NOT NULL,
  `name` varchar(255) NOT NULL COMMENT 'Variable name without secret_ prefix',
  `value_encrypted` text NOT NULL COMMENT 'Encrypted via Crypto::encrypt()',
  `created` datetime NOT NULL,
  `modified` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `run_secret_name` (`run_id`,`name`),
  CONSTRAINT `survey_run_secrets_ibfk_1` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_run_sessions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `session` varchar(64) NOT NULL,
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
  KEY `fk_survey_run_sessions_survey_units1_idx` (`current_unit_session_id`),
  KEY `idx_run_created` (`run_id`,`created`),
  KEY `idx_run_last_access` (`run_id`,`last_access`),
  CONSTRAINT `fk_survey_run_sessions_survey_runs1` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_run_sessions_survey_users1` FOREIGN KEY (`user_id`) REFERENCES `survey_users` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_run_settings` (
  `run_session_id` int(10) unsigned NOT NULL,
  `settings` mediumtext DEFAULT NULL,
  PRIMARY KEY (`run_session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_run_special_units` (
  `id` int(10) unsigned NOT NULL,
  `run_id` int(10) unsigned NOT NULL,
  `type` varchar(25) NOT NULL,
  `description` varchar(225) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `run_id` (`run_id`),
  KEY `type` (`type`),
  CONSTRAINT `survey_run_special_units_ibfk_1` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `survey_run_special_units_ibfk_2` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_run_units` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned DEFAULT NULL,
  `position` smallint(6) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`,`run_id`),
  UNIQUE KEY `position_run` (`run_id`,`position`),
  KEY `fk_survey_run_data_survey_run_items1_idx` (`unit_id`),
  CONSTRAINT `fk_suru` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `fk_suru_it` FOREIGN KEY (`unit_id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `setting` varchar(255) NOT NULL,
  `value` text NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting` (`setting`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_shuffles` (
  `id` int(10) unsigned NOT NULL,
  `groups` smallint(5) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_shuffle_unit` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_studies` (
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
  `expire_invitation_after` int(10) unsigned DEFAULT NULL,
  `expire_invitation_grace` int(10) unsigned DEFAULT NULL,
  `enable_instant_validation` tinyint(1) DEFAULT 1,
  `original_file` varchar(225) DEFAULT NULL,
  `google_file_id` varchar(150) DEFAULT NULL,
  `unlinked` tinyint(1) DEFAULT 0,
  `hide_results` tinyint(4) NOT NULL DEFAULT 0,
  `use_paging` tinyint(4) NOT NULL DEFAULT 0,
  `rendering_mode` enum('v1','v2') NOT NULL DEFAULT 'v1',
  `offline_mode` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `allow_previous` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `layout` enum('default','solo') NOT NULL DEFAULT 'default',
  `option_keys` tinyint(1) NOT NULL DEFAULT 1,
  `last_iteration` int(10) unsigned NOT NULL DEFAULT 0,
  `language` varchar(35) NOT NULL DEFAULT 'en' COMMENT 'BCP 47 tag for participant-facing engine chrome (form_v2 i18n)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_by_user` (`user_id`,`name`),
  CONSTRAINT `fk_study_unit` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_studies_survey_users` FOREIGN KEY (`user_id`) REFERENCES `survey_users` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_study_metrics` (
  `study_id` int(10) unsigned NOT NULL,
  `begun` int(10) unsigned NOT NULL DEFAULT 0,
  `finished` int(10) unsigned NOT NULL DEFAULT 0,
  `testers` int(10) unsigned NOT NULL DEFAULT 0,
  `real_users` int(10) unsigned NOT NULL DEFAULT 0,
  `sum_log_duration` double NOT NULL DEFAULT 0,
  `n_durations` int(10) unsigned NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`study_id`),
  CONSTRAINT `fk_study_metrics_study` FOREIGN KEY (`study_id`) REFERENCES `survey_studies` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_text_messages` (
  `id` int(10) unsigned NOT NULL,
  `account_id` int(10) unsigned DEFAULT NULL,
  `recipient_field` varchar(255) DEFAULT NULL,
  `body` mediumtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_survey_emails_survey_email_accounts1_idx` (`account_id`),
  CONSTRAINT `fk_email_acc0` FOREIGN KEY (`account_id`) REFERENCES `survey_email_accounts` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `fk_email_unit0` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_units` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `type` varchar(20) DEFAULT NULL,
  `form_study_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `type` (`type`),
  KEY `form_study_id` (`form_study_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_unit_sessions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `unit_id` int(10) unsigned NOT NULL,
  `run_unit_id` int(10) unsigned DEFAULT NULL,
  `iteration` int(10) unsigned DEFAULT NULL,
  `study_iteration` int(10) unsigned DEFAULT NULL,
  `layout` varchar(16) DEFAULT NULL,
  `run_session_id` int(10) unsigned DEFAULT NULL,
  `created` datetime NOT NULL,
  `expires` datetime DEFAULT NULL,
  `queued` tinyint(3) NOT NULL DEFAULT 0,
  `result` varchar(40) DEFAULT NULL,
  `result_log` mediumtext DEFAULT NULL,
  `ended` datetime DEFAULT NULL,
  `expired` datetime DEFAULT NULL,
  `state` enum('PENDING','RUNNING','WAITING_USER','WAITING_TIMER','ENDED','EXPIRED','SUPERSEDED') DEFAULT NULL,
  `state_log` longtext DEFAULT NULL CHECK (`state_log` is null or json_valid(`state_log`)),
  `idempotency_key` varchar(128) DEFAULT NULL,
  `execution_time` decimal(12,3) unsigned DEFAULT NULL COMMENT 'Cumulative wall-clock seconds in UnitSession::execute(), incl. OpenCPU (issue #608)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idemp_unit_session` (`idempotency_key`),
  UNIQUE KEY `idx_run_unit_iter` (`run_session_id`,`run_unit_id`,`iteration`),
  KEY `session_uq` (`created`,`run_session_id`,`unit_id`),
  KEY `fk_survey_sessions_survey_units1_idx` (`unit_id`),
  KEY `ended` (`ended`),
  KEY `queued_expires` (`queued`,`expires`),
  KEY `results` (`created`,`result`,`run_session_id`),
  KEY `idx_state` (`state`),
  KEY `idx_study_iteration` (`study_iteration`),
  KEY `idx_uxec_compute` (`run_session_id`,`execution_time`,`created`),
  KEY `idx_run_unit_id_live` (`run_unit_id`,`ended`,`expired`),
  KEY `idx_current_lookup` (`run_session_id`,`unit_id`,`ended`,`expired`,`id`),
  KEY `idx_run_session_created_id` (`run_session_id`,`created`,`id`),
  CONSTRAINT `fk_survey_sessions_survey_units1` FOREIGN KEY (`unit_id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_unit_sessions_survey_run_sessions1` FOREIGN KEY (`run_session_id`) REFERENCES `survey_run_sessions` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_uploaded_files` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int(10) unsigned NOT NULL,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `original_file_name` varchar(255) DEFAULT NULL,
  `new_file_path` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`,`run_id`),
  UNIQUE KEY `unique` (`run_id`,`original_file_name`),
  CONSTRAINT `fk_survey_uploaded_files_survey_runs1` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `survey_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_code` varchar(64) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL,
  `first_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) DEFAULT NULL,
  `affiliation` varchar(350) DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `admin` tinyint(1) DEFAULT 0,
  `email` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `email_verification_hash` varchar(255) CHARACTER SET latin1 COLLATE latin1_bin DEFAULT NULL,
  `email_verified` tinyint(1) DEFAULT 0,
  `reset_token_hash` varchar(255) CHARACTER SET latin1 COLLATE latin1_bin DEFAULT NULL,
  `reset_token_expiry` datetime DEFAULT NULL,
  `mobile_number` varchar(30) DEFAULT NULL,
  `mobile_verification_hash` varchar(255) DEFAULT NULL,
  `mobile_verified` tinyint(1) DEFAULT 0,
  `referrer_code` varchar(255) DEFAULT NULL,
  `2fa_code` varchar(255) DEFAULT '',
  `backup_codes` varchar(255) DEFAULT '',
  `compute_limit_monthly` decimal(12,3) unsigned DEFAULT NULL COMMENT 'Monthly compute budget in seconds (issue #608). NULL=inherit global default; 0=unlimited; superadmin-set only',
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_code_UNIQUE` (`user_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `user_uploaded_files` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `study_id` int(10) unsigned DEFAULT NULL,
  `unit_session_id` int(10) unsigned DEFAULT NULL,
  `original_filename` varchar(255) NOT NULL,
  `stored_path` varchar(1000) NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_uploaded_files_study` (`study_id`),
  KEY `idx_user_uploaded_files_session` (`unit_session_id`),
  CONSTRAINT `fk_user_uploaded_files_session` FOREIGN KEY (`unit_session_id`) REFERENCES `survey_unit_sessions` (`id`) ON DELETE SET NULL ON UPDATE NO ACTION,
  CONSTRAINT `fk_user_uploaded_files_study` FOREIGN KEY (`study_id`) REFERENCES `survey_studies` (`id`) ON DELETE SET NULL ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
