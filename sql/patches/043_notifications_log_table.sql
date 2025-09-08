CREATE TABLE IF NOT EXISTS `survey_notifications` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `run_id` int(11) UNSIGNED NOT NULL,
    `session_id` int(11) UNSIGNED NOT NULL,
    `message` text NOT NULL,
    `type` enum('error','warning','info') NOT NULL DEFAULT 'error',
    `created` datetime NOT NULL,
    `recipient_id` int(11) UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `run_id` (`run_id`),
    KEY `session_id` (`session_id`),
    KEY `recipient_id` (`recipient_id`),
    KEY `created` (`created`),
    CONSTRAINT `survey_notifications_ibfk_1` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `survey_notifications_ibfk_2` FOREIGN KEY (`session_id`) REFERENCES `survey_unit_sessions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `survey_notifications_ibfk_3` FOREIGN KEY (`recipient_id`) REFERENCES `survey_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;