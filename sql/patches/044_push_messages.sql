-- Modify the survey_runs table to store VAPID keys
ALTER TABLE survey_runs 
    ADD COLUMN vapid_public_key TEXT NULL,
    ADD COLUMN vapid_private_key TEXT NULL;

-- Fix survey_run_sessions.id to be unsigned
ALTER TABLE survey_unit_sessions
  DROP FOREIGN KEY fk_survey_unit_sessions_survey_run_sessions1;

ALTER TABLE survey_run_sessions MODIFY id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE survey_unit_sessions MODIFY run_session_id INT(10) UNSIGNED;

ALTER TABLE survey_unit_sessions
  ADD CONSTRAINT fk_survey_unit_sessions_survey_run_sessions1
  FOREIGN KEY (run_session_id)
  REFERENCES survey_run_sessions(id)
  ON DELETE CASCADE ON UPDATE NO ACTION;

-- Create the push_logs table for logging notifications
CREATE TABLE push_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    unit_session_id INT UNSIGNED NOT NULL,
    session_id INT UNSIGNED NOT NULL,
    run_id INT UNSIGNED NOT NULL,
    `message` TEXT NULL,
    `status` VARCHAR(20) NULL,
    error_message TEXT NULL,
    attempt INT DEFAULT 1 NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
    
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),

    -- Define the keys explicitly before the constraints
    KEY `fk_push_logs_unit_sessions_idx` (`unit_session_id`),
    KEY `fk_push_logs_run_sessions_idx` (`session_id`),
    KEY `fk_push_logs_runs_idx` (`run_id`),

    -- Foreign Key Constraints
    CONSTRAINT `fk_push_logs_unit_sessions` 
        FOREIGN KEY (`unit_session_id`) 
        REFERENCES `survey_unit_sessions` (`id`) 
        ON DELETE CASCADE 
        ON UPDATE NO ACTION,

    CONSTRAINT `fk_push_logs_run_sessions` 
        FOREIGN KEY (`session_id`) 
        REFERENCES `survey_run_sessions` (`id`) 
        ON DELETE CASCADE 
        ON UPDATE NO ACTION,

    CONSTRAINT `fk_push_logs_runs` 
        FOREIGN KEY (`run_id`) 
        REFERENCES `survey_runs` (`id`) 
        ON DELETE CASCADE 
        ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create the push_messages table
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
    modified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
    
    PRIMARY KEY (id),

    -- Define the key explicitly before the constraint
    KEY `fk_push_messages_units_idx` (`id`),

    -- Foreign Key Constraint
    CONSTRAINT `fk_push_messages_units` 
        FOREIGN KEY (`id`) 
        REFERENCES `survey_units` (`id`) 
        ON DELETE CASCADE 
        ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;