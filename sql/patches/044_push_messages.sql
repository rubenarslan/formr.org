-- VAPID keys and push messaging (idempotent)
ALTER TABLE survey_runs ADD COLUMN IF NOT EXISTS vapid_public_key TEXT NULL;
ALTER TABLE survey_runs ADD COLUMN IF NOT EXISTS vapid_private_key TEXT NULL;

CALL formr_drop_foreign_key_if_exists('survey_unit_sessions', 'fk_survey_unit_sessions_survey_run_sessions1');
ALTER TABLE survey_run_sessions MODIFY id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE survey_unit_sessions MODIFY run_session_id INT(10) UNSIGNED;
CALL formr_add_foreign_key_if_not_exists('survey_unit_sessions', 'fk_survey_unit_sessions_survey_run_sessions1', 'run_session_id', 'survey_run_sessions', 'id', 'CASCADE', 'NO ACTION');

CREATE TABLE IF NOT EXISTS push_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    unit_session_id INT UNSIGNED NOT NULL,
    run_id INT UNSIGNED NOT NULL,
    `message` TEXT NULL,
    `status` VARCHAR(20) NULL,
    error_message TEXT NULL,
    attempt INT DEFAULT 1 NOT NULL,
    created TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
    INDEX idx_status (status),
    INDEX idx_created (created),
    KEY `fk_push_logs_unit_sessions_idx` (`unit_session_id`),
    KEY `fk_push_logs_runs_idx` (`run_id`),
    CONSTRAINT `fk_push_logs_unit_sessions` FOREIGN KEY (`unit_session_id`) REFERENCES `survey_unit_sessions` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
    CONSTRAINT `fk_push_logs_runs` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS push_messages (
    id INT UNSIGNED NOT NULL,
    `message` TEXT NULL,
    topic VARCHAR(255) DEFAULT NULL,
    `priority` VARCHAR(20) NOT NULL DEFAULT 'normal',
    time_to_live INT NOT NULL DEFAULT 86400,
    badge_count INT DEFAULT NULL,
    vibrate TINYINT(1) NOT NULL DEFAULT 1,
    require_interaction TINYINT(1) NOT NULL DEFAULT 0,
    renotify TINYINT(1) NOT NULL DEFAULT 0,
    silent TINYINT(1) NOT NULL DEFAULT 0,
    created TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
    modified TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
    PRIMARY KEY (id),
    KEY `fk_push_messages_units_idx` (`id`),
    CONSTRAINT `fk_push_messages_units` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
