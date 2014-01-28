SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='TRADITIONAL,ALLOW_INVALID_DATES';


-- -----------------------------------------------------
-- Table `survey_users`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_users` ;

CREATE  TABLE IF NOT EXISTS `survey_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `user_code` CHAR(64) BINARY NOT NULL ,
  `admin` TINYINT(1) NULL DEFAULT 0 ,
  `email` VARCHAR(255) NULL ,
  `password` VARCHAR(255) NULL ,
  `email_verification_hash` VARCHAR(255) BINARY NULL ,
  `email_verified` TINYINT(1) NULL DEFAULT 0 ,
  `reset_token_hash` VARCHAR(255) BINARY NULL ,
  `reset_token_expiry` DATETIME NULL ,
  PRIMARY KEY (`id`) ,
  UNIQUE INDEX `user_code_UNIQUE` (`user_code` ASC) )
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_units`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_units` ;

CREATE  TABLE IF NOT EXISTS `survey_units` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `type` VARCHAR(20) NULL ,
  `created` DATETIME NULL ,
  `modified` DATETIME NULL ,
  PRIMARY KEY (`id`) )
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_studies`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_studies` ;

CREATE  TABLE IF NOT EXISTS `survey_studies` (
  `id` INT UNSIGNED NOT NULL ,
  `user_id` INT UNSIGNED NOT NULL ,
  `name` VARCHAR(255) NULL ,
  `logo_name` VARCHAR(255) NULL ,
  INDEX `fk_survey_studies_survey_users_idx` (`user_id` ASC) ,
  INDEX `fk_survey_studies_run_items1_idx` (`id` ASC) ,
  PRIMARY KEY (`id`) ,
  CONSTRAINT `fk_survey_studies_survey_users`
    FOREIGN KEY (`user_id` )
    REFERENCES `survey_users` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_study_unit`
    FOREIGN KEY (`id` )
    REFERENCES `survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_runs`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_runs` ;

CREATE  TABLE IF NOT EXISTS `survey_runs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `user_id` INT UNSIGNED NOT NULL ,
  `name` VARCHAR(45) NULL ,
  `api_secret_hash` VARCHAR(255) BINARY NULL ,
  `cron_active` TINYINT(1) NULL DEFAULT 0 ,
  `public` TINYINT(1) NULL DEFAULT 0 ,
  `reminder_email` INT UNSIGNED NULL ,
  `service_message` INT UNSIGNED NULL ,
  `display_service_message` TINYINT(1) NULL ,
  PRIMARY KEY (`id`) ,
  INDEX `fk_runs_survey_users1_idx` (`user_id` ASC) ,
  INDEX `fk_survey_runs_survey_units1_idx` (`reminder_email` ASC) ,
  INDEX `fk_survey_runs_survey_units2_idx` (`service_message` ASC) ,
  CONSTRAINT `fk_runs_survey_users1`
    FOREIGN KEY (`user_id` )
    REFERENCES `survey_users` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_runs_survey_units1`
    FOREIGN KEY (`reminder_email` )
    REFERENCES `survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_runs_survey_units2`
    FOREIGN KEY (`service_message` )
    REFERENCES `survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_run_units`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_run_units` ;

CREATE  TABLE IF NOT EXISTS `survey_run_units` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `run_id` INT UNSIGNED NOT NULL ,
  `unit_id` INT UNSIGNED NULL ,
  `position` SMALLINT NOT NULL ,
  PRIMARY KEY (`id`, `run_id`) ,
  INDEX `fk_survey_run_data_survey_runs1_idx` (`run_id` ASC) ,
  INDEX `fk_survey_run_data_survey_run_items1_idx` (`unit_id` ASC) ,
  CONSTRAINT `fk_suru`
    FOREIGN KEY (`run_id` )
    REFERENCES `survey_runs` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_suru_it`
    FOREIGN KEY (`unit_id` )
    REFERENCES `survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_items`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_items` ;

CREATE  TABLE IF NOT EXISTS `survey_items` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT ,
  `study_id` INT UNSIGNED NOT NULL ,
  `type` VARCHAR(100) NOT NULL ,
  `choice_list` VARCHAR(255) NULL DEFAULT NULL ,
  `type_options` VARCHAR(255) NULL DEFAULT NULL ,
  `name` VARCHAR(255) NOT NULL ,
  `label` TEXT NULL DEFAULT NULL ,
  `label_parsed` TEXT NULL DEFAULT NULL ,
  `optional` TINYINT NULL DEFAULT NULL ,
  `class` VARCHAR(255) NULL DEFAULT NULL ,
  `showif` TEXT NULL DEFAULT NULL ,
  `value` TEXT NULL DEFAULT NULL ,
  `order` VARCHAR(4) NULL ,
  PRIMARY KEY (`id`) ,
  UNIQUE INDEX `study_item` (`study_id` ASC, `name` ASC) ,
  INDEX `fk_survey_items_survey_studies1_idx` (`study_id` ASC) ,
  CONSTRAINT `fk_survey_items_survey_studies1`
    FOREIGN KEY (`study_id` )
    REFERENCES `survey_studies` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_items_display`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_items_display` ;

CREATE  TABLE IF NOT EXISTS `survey_items_display` (
  `id` INT NOT NULL AUTO_INCREMENT ,
  `item_id` INT UNSIGNED NOT NULL ,
  `session_id` INT UNSIGNED NOT NULL ,
  `created` DATETIME NULL DEFAULT NULL ,
  `modified` DATETIME NULL DEFAULT NULL ,
  `answered_time` DATETIME NULL DEFAULT NULL ,
  `answered` TINYINT UNSIGNED NULL DEFAULT NULL ,
  `displaycount` TINYINT UNSIGNED NULL DEFAULT NULL ,
  PRIMARY KEY (`id`) ,
  INDEX `id_idx` (`item_id` ASC) ,
  UNIQUE INDEX `session_item_views` (`session_id` ASC, `item_id` ASC) ,
  CONSTRAINT `itemid`
    FOREIGN KEY (`item_id` )
    REFERENCES `survey_items` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_run_sessions`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_run_sessions` ;

CREATE  TABLE IF NOT EXISTS `survey_run_sessions` (
  `id` INT NOT NULL AUTO_INCREMENT ,
  `run_id` INT UNSIGNED NOT NULL ,
  `user_id` INT UNSIGNED NULL DEFAULT NULL ,
  `session` CHAR(64) BINARY NOT NULL ,
  `created` DATETIME NULL ,
  `ended` DATETIME NULL DEFAULT NULL ,
  `last_access` DATETIME NULL DEFAULT NULL ,
  `position` SMALLINT NULL DEFAULT NULL ,
  `current_unit_id` INT UNSIGNED NULL ,
  PRIMARY KEY (`id`) ,
  INDEX `fk_survey_run_sessions_survey_runs1_idx` (`run_id` ASC) ,
  INDEX `fk_survey_run_sessions_survey_users1_idx` (`user_id` ASC) ,
  UNIQUE INDEX `run_user` (`user_id` ASC, `run_id` ASC) ,
  UNIQUE INDEX `run_session` (`session` ASC, `run_id` ASC) ,
  INDEX `fk_survey_run_sessions_survey_units1_idx` (`current_unit_id` ASC) ,
  CONSTRAINT `fk_survey_run_sessions_survey_runs1`
    FOREIGN KEY (`run_id` )
    REFERENCES `survey_runs` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_run_sessions_survey_users1`
    FOREIGN KEY (`user_id` )
    REFERENCES `survey_users` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_run_sessions_survey_units1`
    FOREIGN KEY (`current_unit_id` )
    REFERENCES `survey_units` (`id` )
    ON DELETE SET NULL
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_unit_sessions`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_unit_sessions` ;

CREATE  TABLE IF NOT EXISTS `survey_unit_sessions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `unit_id` INT UNSIGNED NOT NULL ,
  `run_session_id` INT NULL ,
  `created` DATETIME NOT NULL ,
  `ended` DATETIME NULL DEFAULT NULL ,
  PRIMARY KEY (`id`) ,
  INDEX `session_uq` (`created` ASC, `run_session_id` ASC, `unit_id` ASC) ,
  INDEX `fk_survey_sessions_survey_units1_idx` (`unit_id` ASC) ,
  INDEX `fk_survey_unit_sessions_survey_run_sessions1_idx` (`run_session_id` ASC) ,
  CONSTRAINT `fk_survey_sessions_survey_units1`
    FOREIGN KEY (`unit_id` )
    REFERENCES `survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_unit_sessions_survey_run_sessions1`
    FOREIGN KEY (`run_session_id` )
    REFERENCES `survey_run_sessions` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_settings`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_settings` ;

CREATE  TABLE IF NOT EXISTS `survey_settings` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT ,
  `study_id` INT UNSIGNED NOT NULL ,
  `key` VARCHAR(100) NULL DEFAULT NULL ,
  `value` TEXT NULL DEFAULT NULL ,
  PRIMARY KEY (`id`) ,
  UNIQUE INDEX `setting` (`study_id` ASC, `key` ASC) ,
  INDEX `fk_survey_settings_survey_studies1_idx` (`study_id` ASC) ,
  CONSTRAINT `fk_survey_settings_survey_studies1`
    FOREIGN KEY (`study_id` )
    REFERENCES `survey_studies` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_email_accounts`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_email_accounts` ;

CREATE  TABLE IF NOT EXISTS `survey_email_accounts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `user_id` INT UNSIGNED NOT NULL ,
  `from` VARCHAR(255) NULL ,
  `from_name` VARCHAR(255) NULL ,
  `host` VARCHAR(255) NULL ,
  `port` SMALLINT NULL ,
  `tls` TINYINT NULL ,
  `username` VARCHAR(255) NULL ,
  `password` VARCHAR(255) NULL ,
  INDEX `fk_survey_emails_survey_users1_idx` (`user_id` ASC) ,
  PRIMARY KEY (`id`) ,
  CONSTRAINT `fk_email_user`
    FOREIGN KEY (`user_id` )
    REFERENCES `survey_users` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_externals`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_externals` ;

CREATE  TABLE IF NOT EXISTS `survey_externals` (
  `id` INT UNSIGNED NOT NULL ,
  `address` VARCHAR(255) NULL ,
  `api_end` TINYINT(1) NULL DEFAULT 0 ,
  INDEX `fk_survey_forks_survey_run_items1_idx` (`id` ASC) ,
  PRIMARY KEY (`id`) ,
  CONSTRAINT `fk_external_unit`
    FOREIGN KEY (`id` )
    REFERENCES `survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_pauses`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_pauses` ;

CREATE  TABLE IF NOT EXISTS `survey_pauses` (
  `id` INT UNSIGNED NOT NULL ,
  `wait_until_time` TIME NULL ,
  `wait_until_date` DATE NULL ,
  `wait_minutes` INT NULL ,
  `relative_to` VARCHAR(255) NULL ,
  `body` TEXT NULL ,
  `body_parsed` TEXT NULL ,
  INDEX `fk_survey_breaks_survey_run_items1_idx` (`id` ASC) ,
  PRIMARY KEY (`id`) ,
  CONSTRAINT `fk_survey_breaks_survey_run_items1`
    FOREIGN KEY (`id` )
    REFERENCES `survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_branches`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_branches` ;

CREATE  TABLE IF NOT EXISTS `survey_branches` (
  `id` INT UNSIGNED NOT NULL ,
  `condition` TEXT NULL ,
  `if_true` SMALLINT NULL ,
  `automatically_jump` TINYINT(1) NULL DEFAULT 1 ,
  `automatically_go_on` TINYINT(1) NULL DEFAULT 1 ,
  INDEX `fk_survey_branch_survey_units1_idx` (`id` ASC) ,
  PRIMARY KEY (`id`) ,
  CONSTRAINT `fk_branch_unit`
    FOREIGN KEY (`id` )
    REFERENCES `survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_emails`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_emails` ;

CREATE  TABLE IF NOT EXISTS `survey_emails` (
  `id` INT UNSIGNED NOT NULL ,
  `account_id` INT UNSIGNED NULL ,
  `subject` VARCHAR(255) NULL ,
  `recipient_field` VARCHAR(255) NULL ,
  `body` TEXT NULL ,
  `body_parsed` TEXT NULL ,
  `html` TINYINT(1) NULL ,
  INDEX `fk_survey_emails_survey_units1_idx` (`id` ASC) ,
  PRIMARY KEY (`id`) ,
  INDEX `fk_survey_emails_survey_email_accounts1_idx` (`account_id` ASC) ,
  CONSTRAINT `fk_email_unit`
    FOREIGN KEY (`id` )
    REFERENCES `survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_email_acc`
    FOREIGN KEY (`account_id` )
    REFERENCES `survey_email_accounts` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_email_log`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_email_log` ;

CREATE  TABLE IF NOT EXISTS `survey_email_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `session_id` INT UNSIGNED NULL ,
  `email_id` INT UNSIGNED NULL ,
  `created` DATETIME NOT NULL ,
  `recipient` VARCHAR(255) NULL ,
  PRIMARY KEY (`id`) ,
  INDEX `fk_survey_email_log_survey_emails1_idx` (`email_id` ASC) ,
  INDEX `fk_survey_email_log_survey_unit_sessions1_idx` (`session_id` ASC) ,
  CONSTRAINT `fk_survey_email_log_survey_emails1`
    FOREIGN KEY (`email_id` )
    REFERENCES `survey_emails` (`id` )
    ON DELETE SET NULL
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_email_log_survey_unit_sessions1`
    FOREIGN KEY (`session_id` )
    REFERENCES `survey_unit_sessions` (`id` )
    ON DELETE SET NULL
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_pages`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_pages` ;

CREATE  TABLE IF NOT EXISTS `survey_pages` (
  `id` INT UNSIGNED NOT NULL ,
  `body` TEXT NULL ,
  `body_parsed` TEXT NULL ,
  `title` VARCHAR(255) NULL ,
  `end` TINYINT(1) NULL DEFAULT 1 ,
  INDEX `fk_survey_feedback_survey_units1_idx` (`id` ASC) ,
  PRIMARY KEY (`id`) ,
  CONSTRAINT `fk_page_unit`
    FOREIGN KEY (`id` )
    REFERENCES `survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_results`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_results` ;

CREATE  TABLE IF NOT EXISTS `survey_results` (
  `session_id` INT UNSIGNED NOT NULL ,
  `study_id` INT UNSIGNED NOT NULL ,
  `modified` DATETIME NULL DEFAULT NULL ,
  `created` DATETIME NULL DEFAULT NULL ,
  `ended` DATETIME NULL DEFAULT NULL ,
  INDEX `fk_survey_results_survey_unit_sessions1_idx` (`session_id` ASC) ,
  INDEX `fk_survey_results_survey_studies1_idx` (`study_id` ASC) ,
  PRIMARY KEY (`session_id`) ,
  CONSTRAINT `fk_survey_results_survey_unit_sessions1`
    FOREIGN KEY (`session_id` )
    REFERENCES `survey_unit_sessions` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_results_survey_studies1`
    FOREIGN KEY (`study_id` )
    REFERENCES `survey_studies` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_item_choices`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_item_choices` ;

CREATE  TABLE IF NOT EXISTS `survey_item_choices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `study_id` INT UNSIGNED NOT NULL ,
  `list_name` VARCHAR(255) NULL ,
  `name` VARCHAR(255) NULL ,
  `label` TEXT NULL DEFAULT NULL ,
  `label_parsed` TEXT NULL DEFAULT NULL ,
  PRIMARY KEY (`id`) ,
  INDEX `fk_survey_item_choices_survey_studies1_idx` (`study_id` ASC) ,
  INDEX `listname` (`list_name` ASC) ,
  CONSTRAINT `fk_survey_item_choices_survey_studies1`
    FOREIGN KEY (`study_id` )
    REFERENCES `survey_studies` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_reports`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_reports` ;

CREATE  TABLE IF NOT EXISTS `survey_reports` (
  `session_id` INT UNSIGNED NOT NULL ,
  `unit_id` INT UNSIGNED NOT NULL ,
  `created` DATETIME NULL DEFAULT NULL ,
  `last_viewed` DATETIME NULL DEFAULT NULL ,
  `body_knit` TEXT NULL ,
  INDEX `fk_survey_results_survey_unit_sessions1_idx` (`session_id` ASC) ,
  PRIMARY KEY (`session_id`) ,
  INDEX `fk_survey_reports_survey_units1_idx` (`unit_id` ASC) ,
  CONSTRAINT `fk_survey_results_survey_unit_sessions10`
    FOREIGN KEY (`session_id` )
    REFERENCES `survey_unit_sessions` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_reports_survey_units1`
    FOREIGN KEY (`unit_id` )
    REFERENCES `survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_shuffles`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_shuffles` ;

CREATE  TABLE IF NOT EXISTS `survey_shuffles` (
  `id` INT UNSIGNED NOT NULL ,
  `groups` SMALLINT UNSIGNED NULL ,
  INDEX `fk_survey_branch_survey_units1_idx` (`id` ASC) ,
  PRIMARY KEY (`id`) ,
  CONSTRAINT `fk_shuffle_unit`
    FOREIGN KEY (`id` )
    REFERENCES `survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `shuffle`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `shuffle` ;

CREATE  TABLE IF NOT EXISTS `shuffle` (
  `session_id` INT UNSIGNED NOT NULL ,
  `unit_id` INT UNSIGNED NOT NULL ,
  `created` DATETIME NULL DEFAULT NULL ,
  `group` SMALLINT UNSIGNED NULL ,
  INDEX `fk_survey_results_survey_unit_sessions1_idx` (`session_id` ASC) ,
  PRIMARY KEY (`session_id`) ,
  INDEX `fk_survey_reports_survey_units1_idx` (`unit_id` ASC) ,
  CONSTRAINT `fk_unit_sessions_shuffle`
    FOREIGN KEY (`session_id` )
    REFERENCES `survey_unit_sessions` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_unit_shuffle`
    FOREIGN KEY (`unit_id` )
    REFERENCES `survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_cron_log`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_cron_log` ;

CREATE  TABLE IF NOT EXISTS `survey_cron_log` (
  `id` INT NOT NULL AUTO_INCREMENT ,
  `run_id` INT UNSIGNED NOT NULL ,
  `created` DATETIME NULL ,
  `ended` DATETIME NULL ,
  `sessions` INT UNSIGNED NULL ,
  `skipforwards` INT UNSIGNED NULL ,
  `skipbackwards` INT UNSIGNED NULL ,
  `pauses` INT UNSIGNED NULL ,
  `emails` INT UNSIGNED NULL ,
  `shuffles` INT UNSIGNED NULL ,
  `errors` INT UNSIGNED NULL ,
  `warnings` INT UNSIGNED NULL ,
  `notices` INT UNSIGNED NULL ,
  `message` TEXT NULL ,
  PRIMARY KEY (`id`) ,
  INDEX `fk_survey_cron_log_survey_runs1_idx` (`run_id` ASC) ,
  CONSTRAINT `fk_survey_cron_log_survey_runs1`
    FOREIGN KEY (`run_id` )
    REFERENCES `survey_runs` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;



SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
