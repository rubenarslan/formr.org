ALTER TABLE `survey_items` CHANGE `label` `label` mediumtext COLLATE utf8mb4_unicode_ci;
ALTER TABLE `survey_run_sessions` CHANGE `session` `session` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL;
ALTER TABLE `survey_users` CHANGE `user_code` `user_code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL;
