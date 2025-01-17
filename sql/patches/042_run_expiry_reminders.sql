CREATE TABLE `survey_run_expiry_reminders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int(10) unsigned NOT NULL,
  `reminder_type` enum('6_months','2_months','1_month','1_week','1_day') NOT NULL,
  `sent_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `run_reminder_type` (`run_id`, `reminder_type`),
  KEY `fk_survey_run_expiry_reminders_survey_runs1_idx` (`run_id`),
  CONSTRAINT `fk_survey_run_expiry_reminders_survey_runs1` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; 