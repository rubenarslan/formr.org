ALTER TABLE `survey_runs`
    ADD `expiresOn` datetime DEFAULT NULL;

CREATE TABLE `user_uploaded_files` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `study_id` INT UNSIGNED NULL,
    `unit_session_id` INT UNSIGNED NULL,
    `original_filename` VARCHAR(255) NOT NULL,
    `stored_path` VARCHAR(1000) NOT NULL,
    `created` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_user_uploaded_files_study` (`study_id`),
    INDEX `idx_user_uploaded_files_session` (`unit_session_id`),
    CONSTRAINT `fk_user_uploaded_files_study`
        FOREIGN KEY (`study_id`)
        REFERENCES `survey_studies` (`id`)
        ON DELETE SET NULL
        ON UPDATE NO ACTION,
    CONSTRAINT `fk_user_uploaded_files_session`
        FOREIGN KEY (`unit_session_id`)
        REFERENCES `survey_unit_sessions` (`id`)
        ON DELETE SET NULL
        ON UPDATE NO ACTION
) ENGINE=InnoDB;
