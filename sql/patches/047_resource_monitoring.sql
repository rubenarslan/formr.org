-- Resource monitoring tables for user compute resource tracking and billing
-- Tracks: survey count, survey items size, run count, uploaded files size, unit sessions count, unit execution times

-- Main resource metrics per user (computed by cron)
CREATE TABLE IF NOT EXISTS `survey_resource_metrics` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `survey_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `survey_items_size_kb` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `run_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `uploaded_files_size_kb` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `unit_sessions_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `last_computed_at` DATETIME NOT NULL,
    `last_run_activity_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `user_id` (`user_id`),
    KEY `last_computed_at` (`last_computed_at`),
    CONSTRAINT `fk_resource_metrics_user` FOREIGN KEY (`user_id`) REFERENCES `survey_users` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-survey item sizes (for granular reporting, updated on each cron run)
CREATE TABLE IF NOT EXISTS `survey_resource_survey_sizes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `study_id` INT UNSIGNED NOT NULL,
    `items_size_kb` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `computed_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `user_study` (`user_id`, `study_id`),
    KEY `user_id` (`user_id`),
    KEY `study_id` (`study_id`),
    CONSTRAINT `fk_resource_survey_sizes_user` FOREIGN KEY (`user_id`) REFERENCES `survey_users` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
    CONSTRAINT `fk_resource_survey_sizes_study` FOREIGN KEY (`study_id`) REFERENCES `survey_studies` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Unit execution times (logged in real-time when units execute)
-- run_unit_id can be 0 for special units (reminder_email, overview_script, etc.)
CREATE TABLE IF NOT EXISTS `survey_unit_execution_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `run_unit_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `run_id` INT UNSIGNED NOT NULL,
    `unit_session_id` INT UNSIGNED NOT NULL,
    `unit_type` VARCHAR(25) COLLATE utf8mb4_unicode_ci NULL,
    `execution_time_ms` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `run_unit_id` (`run_unit_id`),
    KEY `run_id` (`run_id`),
    KEY `created_at` (`created_at`),
    KEY `unit_type` (`unit_type`),
    CONSTRAINT `fk_unit_exec_run` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
    CONSTRAINT `fk_unit_exec_session` FOREIGN KEY (`unit_session_id`) REFERENCES `survey_unit_sessions` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
