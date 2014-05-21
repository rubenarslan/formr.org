SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='TRADITIONAL,ALLOW_INVALID_DATES';

CREATE SCHEMA IF NOT EXISTS `formr` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci ;
USE `formr` ;

-- -----------------------------------------------------
-- Table `formr`.`survey_users`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_users` (
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
-- Table `formr`.`survey_units`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_units` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `type` VARCHAR(20) NULL ,
  `description` VARCHAR(500) NULL ,
  `created` DATETIME NULL ,
  `modified` DATETIME NULL ,
  PRIMARY KEY (`id`) ,
  INDEX `type` (`type` ASC) )
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `formr`.`survey_studies`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_studies` (
  `id` INT UNSIGNED NOT NULL ,
  `user_id` INT UNSIGNED NOT NULL ,
  `name` VARCHAR(255) NULL ,
  `logo_name` VARCHAR(255) NULL ,
  INDEX `fk_survey_studies_survey_users_idx` (`user_id` ASC) ,
  INDEX `fk_survey_studies_run_items1_idx` (`id` ASC) ,
  PRIMARY KEY (`id`) ,
  UNIQUE INDEX `name` (`name` ASC) ,
  CONSTRAINT `fk_survey_studies_survey_users`
    FOREIGN KEY (`user_id` )
    REFERENCES `formr`.`survey_users` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_study_unit`
    FOREIGN KEY (`id` )
    REFERENCES `formr`.`survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `formr`.`survey_runs`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_runs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `user_id` INT UNSIGNED NOT NULL ,
  `name` VARCHAR(45) NULL ,
  `api_secret_hash` VARCHAR(255) BINARY NULL ,
  `cron_active` TINYINT(1) NULL DEFAULT 0 ,
  `public` TINYINT(1) NULL DEFAULT 0 ,
  `live` TINYINT(1) NULL DEFAULT 1 ,
  `locked` TINYINT(1) NULL DEFAULT 0 ,
  `reminder_email` INT UNSIGNED NULL ,
  `service_message` INT UNSIGNED NULL ,
  `overview_script` INT UNSIGNED NULL ,
  `display_service_message` TINYINT(1) NULL ,
  `title` VARCHAR(255) NULL ,
  `description` VARCHAR(1000) NULL ,
  `public_blurb` TEXT NULL ,
  `header_image_path` VARCHAR(255) NULL ,
  `footer_text` TEXT NULL ,
  `custom_css_path` VARCHAR(255) NULL ,
  `custom_js_path` VARCHAR(255) NULL ,
  PRIMARY KEY (`id`) ,
  INDEX `fk_runs_survey_users1_idx` (`user_id` ASC) ,
  INDEX `fk_survey_runs_survey_units1_idx` (`reminder_email` ASC) ,
  INDEX `fk_survey_runs_survey_units2_idx` (`service_message` ASC) ,
  INDEX `fk_survey_runs_survey_units3_idx` (`overview_script` ASC) ,
  CONSTRAINT `fk_runs_survey_users1`
    FOREIGN KEY (`user_id` )
    REFERENCES `formr`.`survey_users` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_runs_survey_units1`
    FOREIGN KEY (`reminder_email` )
    REFERENCES `formr`.`survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_runs_survey_units2`
    FOREIGN KEY (`service_message` )
    REFERENCES `formr`.`survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_runs_survey_units3`
    FOREIGN KEY (`overview_script` )
    REFERENCES `formr`.`survey_units` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `formr`.`survey_run_units`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_run_units` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `run_id` INT UNSIGNED NOT NULL ,
  `unit_id` INT UNSIGNED NULL ,
  `position` SMALLINT NOT NULL ,
  PRIMARY KEY (`id`, `run_id`) ,
  INDEX `fk_survey_run_data_survey_runs1_idx` (`run_id` ASC) ,
  INDEX `fk_survey_run_data_survey_run_items1_idx` (`unit_id` ASC) ,
  INDEX `position_run` (`run_id` ASC, `position` ASC) ,
  CONSTRAINT `fk_suru`
    FOREIGN KEY (`run_id` )
    REFERENCES `formr`.`survey_runs` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_suru_it`
    FOREIGN KEY (`unit_id` )
    REFERENCES `formr`.`survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `formr`.`survey_items`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_items` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT ,
  `study_id` INT UNSIGNED NOT NULL ,
  `type` VARCHAR(100) NOT NULL ,
  `choice_list` VARCHAR(255) NULL DEFAULT NULL ,
  `type_options` VARCHAR(255) NULL DEFAULT NULL ,
  `name` VARCHAR(255) NOT NULL ,
  `label` TEXT NULL DEFAULT NULL ,
  `label_parsed` MEDIUMTEXT NULL DEFAULT NULL ,
  `optional` TINYINT NULL DEFAULT NULL ,
  `class` VARCHAR(255) NULL DEFAULT NULL ,
  `showif` TEXT NULL DEFAULT NULL ,
  `value` TEXT NULL DEFAULT NULL ,
  `order` VARCHAR(4) NULL ,
  `post_process` TEXT NULL ,
  PRIMARY KEY (`id`) ,
  UNIQUE INDEX `study_item` (`study_id` ASC, `name` ASC) ,
  INDEX `fk_survey_items_survey_studies1_idx` (`study_id` ASC) ,
  INDEX `type` (`study_id` ASC, `type` ASC) ,
  CONSTRAINT `fk_survey_items_survey_studies1`
    FOREIGN KEY (`study_id` )
    REFERENCES `formr`.`survey_studies` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `formr`.`survey_items_display`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_items_display` (
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
  INDEX `answered` (`session_id` ASC, `answered` ASC) ,
  CONSTRAINT `itemid`
    FOREIGN KEY (`item_id` )
    REFERENCES `formr`.`survey_items` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `formr`.`survey_run_sessions`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_run_sessions` (
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
  INDEX `position` (`position` ASC) ,
  CONSTRAINT `fk_survey_run_sessions_survey_runs1`
    FOREIGN KEY (`run_id` )
    REFERENCES `formr`.`survey_runs` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_run_sessions_survey_users1`
    FOREIGN KEY (`user_id` )
    REFERENCES `formr`.`survey_users` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_run_sessions_survey_units1`
    FOREIGN KEY (`current_unit_id` )
    REFERENCES `formr`.`survey_units` (`id` )
    ON DELETE SET NULL
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `formr`.`survey_unit_sessions`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_unit_sessions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `unit_id` INT UNSIGNED NOT NULL ,
  `run_session_id` INT NULL ,
  `created` DATETIME NOT NULL ,
  `ended` DATETIME NULL DEFAULT NULL ,
  PRIMARY KEY (`id`) ,
  INDEX `session_uq` (`created` ASC, `run_session_id` ASC, `unit_id` ASC) ,
  INDEX `fk_survey_sessions_survey_units1_idx` (`unit_id` ASC) ,
  INDEX `fk_survey_unit_sessions_survey_run_sessions1_idx` (`run_session_id` ASC) ,
  INDEX `ended` (`ended` DESC) ,
  CONSTRAINT `fk_survey_sessions_survey_units1`
    FOREIGN KEY (`unit_id` )
    REFERENCES `formr`.`survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_unit_sessions_survey_run_sessions1`
    FOREIGN KEY (`run_session_id` )
    REFERENCES `formr`.`survey_run_sessions` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `formr`.`survey_settings`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_settings` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT ,
  `study_id` INT UNSIGNED NOT NULL ,
  `key` VARCHAR(100) NULL DEFAULT NULL ,
  `value` TEXT NULL DEFAULT NULL ,
  PRIMARY KEY (`id`) ,
  UNIQUE INDEX `setting` (`study_id` ASC, `key` ASC) ,
  INDEX `fk_survey_settings_survey_studies1_idx` (`study_id` ASC) ,
  CONSTRAINT `fk_survey_settings_survey_studies1`
    FOREIGN KEY (`study_id` )
    REFERENCES `formr`.`survey_studies` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `formr`.`survey_email_accounts`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_email_accounts` (
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
    REFERENCES `formr`.`survey_users` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `formr`.`survey_externals`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_externals` (
  `id` INT UNSIGNED NOT NULL ,
  `address` VARCHAR(255) NULL ,
  `api_end` TINYINT(1) NULL DEFAULT 0 ,
  INDEX `fk_survey_forks_survey_run_items1_idx` (`id` ASC) ,
  PRIMARY KEY (`id`) ,
  CONSTRAINT `fk_external_unit`
    FOREIGN KEY (`id` )
    REFERENCES `formr`.`survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `formr`.`survey_pauses`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_pauses` (
  `id` INT UNSIGNED NOT NULL ,
  `wait_until_time` TIME NULL ,
  `wait_until_date` DATE NULL ,
  `wait_minutes` INT NULL ,
  `relative_to` TEXT NULL ,
  `body` MEDIUMTEXT NULL ,
  `body_parsed` MEDIUMTEXT NULL ,
  INDEX `fk_survey_breaks_survey_run_items1_idx` (`id` ASC) ,
  PRIMARY KEY (`id`) ,
  CONSTRAINT `fk_survey_breaks_survey_run_items1`
    FOREIGN KEY (`id` )
    REFERENCES `formr`.`survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `formr`.`survey_branches`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_branches` (
  `id` INT UNSIGNED NOT NULL ,
  `condition` TEXT NULL ,
  `if_true` SMALLINT NULL ,
  `automatically_jump` TINYINT(1) NULL DEFAULT 1 ,
  `automatically_go_on` TINYINT(1) NULL DEFAULT 1 ,
  INDEX `fk_survey_branch_survey_units1_idx` (`id` ASC) ,
  PRIMARY KEY (`id`) ,
  CONSTRAINT `fk_branch_unit`
    FOREIGN KEY (`id` )
    REFERENCES `formr`.`survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `formr`.`survey_emails`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_emails` (
  `id` INT UNSIGNED NOT NULL ,
  `account_id` INT UNSIGNED NULL ,
  `subject` VARCHAR(255) NULL ,
  `recipient_field` VARCHAR(255) NULL ,
  `body` MEDIUMTEXT NULL ,
  `body_parsed` MEDIUMTEXT NULL ,
  `html` TINYINT(1) NULL ,
  INDEX `fk_survey_emails_survey_units1_idx` (`id` ASC) ,
  PRIMARY KEY (`id`) ,
  INDEX `fk_survey_emails_survey_email_accounts1_idx` (`account_id` ASC) ,
  CONSTRAINT `fk_email_unit`
    FOREIGN KEY (`id` )
    REFERENCES `formr`.`survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_email_acc`
    FOREIGN KEY (`account_id` )
    REFERENCES `formr`.`survey_email_accounts` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `formr`.`survey_email_log`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_email_log` (
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
    REFERENCES `formr`.`survey_emails` (`id` )
    ON DELETE SET NULL
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_email_log_survey_unit_sessions1`
    FOREIGN KEY (`session_id` )
    REFERENCES `formr`.`survey_unit_sessions` (`id` )
    ON DELETE SET NULL
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `formr`.`survey_pages`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_pages` (
  `id` INT UNSIGNED NOT NULL ,
  `body` MEDIUMTEXT NULL ,
  `body_parsed` MEDIUMTEXT NULL ,
  `title` VARCHAR(255) NULL ,
  `end` TINYINT(1) NULL DEFAULT 1 ,
  INDEX `fk_survey_feedback_survey_units1_idx` (`id` ASC) ,
  PRIMARY KEY (`id`) ,
  CONSTRAINT `fk_page_unit`
    FOREIGN KEY (`id` )
    REFERENCES `formr`.`survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `formr`.`survey_results`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_results` (
  `session_id` INT UNSIGNED NOT NULL ,
  `study_id` INT UNSIGNED NOT NULL ,
  `modified` DATETIME NULL DEFAULT NULL ,
  `created` DATETIME NULL DEFAULT NULL ,
  `ended` DATETIME NULL DEFAULT NULL ,
  INDEX `fk_survey_results_survey_unit_sessions1_idx` (`session_id` ASC) ,
  INDEX `fk_survey_results_survey_studies1_idx` (`study_id` ASC) ,
  PRIMARY KEY (`session_id`) ,
  INDEX `ending` (`session_id` DESC, `study_id` ASC, `ended` ASC) ,
  CONSTRAINT `fk_survey_results_survey_unit_sessions1`
    FOREIGN KEY (`session_id` )
    REFERENCES `formr`.`survey_unit_sessions` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_results_survey_studies1`
    FOREIGN KEY (`study_id` )
    REFERENCES `formr`.`survey_studies` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `formr`.`survey_item_choices`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_item_choices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `study_id` INT UNSIGNED NOT NULL ,
  `list_name` VARCHAR(255) NULL ,
  `name` VARCHAR(255) NULL ,
  `label` TEXT NULL DEFAULT NULL ,
  `label_parsed` MEDIUMTEXT NULL DEFAULT NULL ,
  PRIMARY KEY (`id`) ,
  INDEX `fk_survey_item_choices_survey_studies1_idx` (`study_id` ASC) ,
  INDEX `listname` (`list_name` ASC) ,
  CONSTRAINT `fk_survey_item_choices_survey_studies1`
    FOREIGN KEY (`study_id` )
    REFERENCES `formr`.`survey_studies` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `formr`.`survey_reports`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_reports` (
  `session_id` INT UNSIGNED NOT NULL ,
  `unit_id` INT UNSIGNED NOT NULL ,
  `created` DATETIME NULL DEFAULT NULL ,
  `last_viewed` DATETIME NULL DEFAULT NULL ,
  `body_knit` MEDIUMTEXT NULL ,
  INDEX `fk_survey_results_survey_unit_sessions1_idx` (`session_id` ASC) ,
  PRIMARY KEY (`session_id`) ,
  INDEX `fk_survey_reports_survey_units1_idx` (`unit_id` ASC) ,
  CONSTRAINT `fk_survey_results_survey_unit_sessions10`
    FOREIGN KEY (`session_id` )
    REFERENCES `formr`.`survey_unit_sessions` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_reports_survey_units1`
    FOREIGN KEY (`unit_id` )
    REFERENCES `formr`.`survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `formr`.`survey_shuffles`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_shuffles` (
  `id` INT UNSIGNED NOT NULL ,
  `groups` SMALLINT UNSIGNED NULL ,
  INDEX `fk_survey_branch_survey_units1_idx` (`id` ASC) ,
  PRIMARY KEY (`id`) ,
  CONSTRAINT `fk_shuffle_unit`
    FOREIGN KEY (`id` )
    REFERENCES `formr`.`survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `formr`.`shuffle`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`shuffle` (
  `session_id` INT UNSIGNED NOT NULL ,
  `unit_id` INT UNSIGNED NOT NULL ,
  `created` DATETIME NULL DEFAULT NULL ,
  `group` SMALLINT UNSIGNED NULL ,
  INDEX `fk_survey_results_survey_unit_sessions1_idx` (`session_id` ASC) ,
  PRIMARY KEY (`session_id`) ,
  INDEX `fk_survey_reports_survey_units1_idx` (`unit_id` ASC) ,
  CONSTRAINT `fk_unit_sessions_shuffle`
    FOREIGN KEY (`session_id` )
    REFERENCES `formr`.`survey_unit_sessions` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_unit_shuffle`
    FOREIGN KEY (`unit_id` )
    REFERENCES `formr`.`survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `formr`.`survey_cron_log`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_cron_log` (
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
    REFERENCES `formr`.`survey_runs` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `formr`.`survey_uploaded_files`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `formr`.`survey_uploaded_files` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `run_id` INT UNSIGNED NOT NULL ,
  `created` DATETIME NULL ,
  `modified` DATETIME NULL ,
  `original_file_name` VARCHAR(255) NULL ,
  `new_file_path` VARCHAR(255) NULL ,
  PRIMARY KEY (`id`, `run_id`) ,
  INDEX `fk_survey_uploaded_files_survey_runs1_idx` (`run_id` ASC) ,
  UNIQUE INDEX `unique` (`run_id` ASC, `original_file_name` ASC) ,
  CONSTRAINT `fk_survey_uploaded_files_survey_runs1`
    FOREIGN KEY (`run_id` )
    REFERENCES `formr`.`survey_runs` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;

USE `formr` ;


SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
