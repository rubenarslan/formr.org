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
  `reset_token_Hash` VARCHAR(255) BINARY NULL ,
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
  `expiry` SMALLINT UNSIGNED NULL ,
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
  `api_secret` CHAR(64) BINARY NULL ,
  `cron_active` TINYINT(1) NULL DEFAULT 0 ,
  `active` TINYINT(1) NULL DEFAULT 0 ,
  PRIMARY KEY (`id`) ,
  INDEX `fk_runs_survey_users1_idx` (`user_id` ASC) ,
  CONSTRAINT `fk_runs_survey_users1`
    FOREIGN KEY (`user_id` )
    REFERENCES `survey_users` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_run_units`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_run_units` ;

CREATE  TABLE IF NOT EXISTS `survey_run_units` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `run_id` INT UNSIGNED NOT NULL ,
  `unit_id` INT UNSIGNED NOT NULL ,
  `position` SMALLINT NOT NULL ,
  PRIMARY KEY (`id`) ,
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
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_items`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_items` ;

CREATE  TABLE IF NOT EXISTS `survey_items` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT ,
  `study_id` INT UNSIGNED NOT NULL ,
  `variablenname` VARCHAR(100) NOT NULL ,
  `wortlaut` TEXT NULL DEFAULT NULL ,
  `altwortlautbasedon` VARCHAR(150) NULL DEFAULT NULL ,
  `altwortlaut` TEXT NULL DEFAULT NULL ,
  `typ` VARCHAR(100) NOT NULL ,
  `antwortformatanzahl` TINYINT NULL DEFAULT NULL ,
  `MCalt1` VARCHAR(255) NULL DEFAULT NULL ,
  `MCalt2` VARCHAR(255) NULL DEFAULT NULL ,
  `MCalt3` VARCHAR(255) NULL DEFAULT NULL ,
  `MCalt4` VARCHAR(255) NULL DEFAULT NULL ,
  `MCalt5` VARCHAR(255) NULL DEFAULT NULL ,
  `MCalt6` VARCHAR(255) NULL DEFAULT NULL ,
  `MCalt7` VARCHAR(255) NULL DEFAULT NULL ,
  `MCalt8` VARCHAR(255) NULL DEFAULT NULL ,
  `MCalt9` VARCHAR(255) NULL DEFAULT NULL ,
  `MCalt10` VARCHAR(255) NULL DEFAULT NULL ,
  `MCalt11` VARCHAR(255) NULL DEFAULT NULL ,
  `MCalt12` VARCHAR(255) NULL DEFAULT NULL ,
  `MCalt13` VARCHAR(255) NULL DEFAULT NULL ,
  `MCalt14` VARCHAR(255) NULL DEFAULT NULL ,
  `optional` TINYINT NULL DEFAULT NULL ,
  `class` VARCHAR(255) NULL DEFAULT NULL ,
  `skipif` TEXT NULL DEFAULT NULL ,
  PRIMARY KEY (`id`) ,
  UNIQUE INDEX `study_item` (`study_id` ASC, `variablenname` ASC) ,
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
  `item_id` INT UNSIGNED NOT NULL ,
  `session_id` INT UNSIGNED NOT NULL ,
  `study_id` INT UNSIGNED NOT NULL ,
  `created` DATETIME NULL DEFAULT NULL ,
  `modified` DATETIME NULL DEFAULT NULL ,
  `answered_time` DATETIME NULL DEFAULT NULL ,
  `answered` TINYINT UNSIGNED NULL DEFAULT NULL ,
  `displaycount` TINYINT UNSIGNED NULL DEFAULT NULL ,
  PRIMARY KEY (`item_id`, `session_id`) ,
  INDEX `id_idx` (`item_id` ASC) ,
  INDEX `fk_survey_items_display_survey_studies1_idx` (`study_id` ASC) ,
  INDEX `session_item_views` (`study_id` ASC, `session_id` ASC, `item_id` ASC) ,
  CONSTRAINT `itemid`
    FOREIGN KEY (`item_id` )
    REFERENCES `survey_items` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_items_display_survey_studies1`
    FOREIGN KEY (`study_id` )
    REFERENCES `survey_studies` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_substitutions`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_substitutions` ;

CREATE  TABLE IF NOT EXISTS `survey_substitutions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `study_id` INT UNSIGNED NOT NULL ,
  `search` VARCHAR(50) NOT NULL ,
  `replace` VARCHAR(100) NOT NULL ,
  `mode` VARCHAR(255) NULL ,
  PRIMARY KEY (`id`) ,
  UNIQUE INDEX `uniq` (`study_id` ASC, `search` ASC, `replace` ASC, `mode` ASC) ,
  INDEX `fk_survey_substitutions_survey_studies1_idx` (`study_id` ASC) ,
  CONSTRAINT `fk_survey_substitutions_survey_studies1`
    FOREIGN KEY (`study_id` )
    REFERENCES `survey_studies` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_run_sessions`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_run_sessions` ;

CREATE  TABLE IF NOT EXISTS `survey_run_sessions` (
  `id` INT NOT NULL ,
  `run_id` INT UNSIGNED NOT NULL ,
  `user_id` INT UNSIGNED NULL ,
  `session` CHAR(64) BINARY NOT NULL ,
  `created` DATETIME NULL ,
  `ended` DATETIME NULL ,
  `position` SMALLINT NULL ,
  PRIMARY KEY (`id`) ,
  INDEX `fk_survey_run_sessions_survey_runs1_idx` (`run_id` ASC) ,
  INDEX `fk_survey_run_sessions_survey_users1_idx` (`user_id` ASC) ,
  UNIQUE INDEX `run_user` (`user_id` ASC, `run_id` ASC) ,
  UNIQUE INDEX `run_session` (`session` ASC, `user_id` ASC, `run_id` ASC) ,
  CONSTRAINT `fk_survey_run_sessions_survey_runs1`
    FOREIGN KEY (`run_id` )
    REFERENCES `survey_runs` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_run_sessions_survey_users1`
    FOREIGN KEY (`user_id` )
    REFERENCES `survey_users` (`id` )
    ON DELETE NO ACTION
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
  `expires` DATETIME NULL ,
  PRIMARY KEY (`id`) ,
  UNIQUE INDEX `session_uq` (`created` ASC, `run_session_id` ASC, `unit_id` ASC) ,
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
    ON DELETE NO ACTION
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
  `username` VARCHAR(100) NULL ,
  `password` VARCHAR(255) NULL ,
  INDEX `fk_survey_emails_survey_users1_idx` (`user_id` ASC) ,
  PRIMARY KEY (`id`) ,
  CONSTRAINT `fk_email_user`
    FOREIGN KEY (`user_id` )
    REFERENCES `survey_users` (`id` )
    ON DELETE NO ACTION
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
    ON DELETE NO ACTION
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
  `message` TEXT NULL ,
  `message_parsed` TEXT NULL ,
  INDEX `fk_survey_breaks_survey_run_items1_idx` (`id` ASC) ,
  PRIMARY KEY (`id`) ,
  CONSTRAINT `fk_survey_breaks_survey_run_items1`
    FOREIGN KEY (`id` )
    REFERENCES `survey_units` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `survey_branches`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `survey_branches` ;

CREATE  TABLE IF NOT EXISTS `survey_branches` (
  `id` INT UNSIGNED NOT NULL ,
  `condition` VARCHAR(2000) NULL ,
  `if_true` SMALLINT NULL ,
  `if_false` SMALLINT NULL ,
  INDEX `fk_survey_branch_survey_units1_idx` (`id` ASC) ,
  PRIMARY KEY (`id`) ,
  CONSTRAINT `fk_branch_unit`
    FOREIGN KEY (`id` )
    REFERENCES `survey_units` (`id` )
    ON DELETE NO ACTION
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
    ON DELETE NO ACTION
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
  `session_id` INT UNSIGNED NOT NULL ,
  `email_id` INT UNSIGNED NOT NULL ,
  `created` DATETIME NOT NULL ,
  `recipient` VARCHAR(255) NULL ,
  PRIMARY KEY (`id`) ,
  INDEX `fk_survey_email_log_survey_emails1_idx` (`email_id` ASC) ,
  INDEX `fk_survey_email_log_survey_unit_sessions1_idx` (`session_id` ASC) ,
  CONSTRAINT `fk_survey_email_log_survey_emails1`
    FOREIGN KEY (`email_id` )
    REFERENCES `survey_emails` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_email_log_survey_unit_sessions1`
    FOREIGN KEY (`session_id` )
    REFERENCES `survey_unit_sessions` (`id` )
    ON DELETE NO ACTION
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
    ON DELETE NO ACTION
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
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_results_survey_studies1`
    FOREIGN KEY (`study_id` )
    REFERENCES `survey_studies` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Placeholder table for view `view_run_unit_sessions`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `view_run_unit_sessions` (`session_id` INT, `created` INT, `ended` INT, `run_name` INT, `id` INT, `user_id` INT, `position` INT, `unit_id` INT, `run_id` INT, `type` INT);

-- -----------------------------------------------------
-- View `view_run_unit_sessions`
-- -----------------------------------------------------
DROP VIEW IF EXISTS `view_run_unit_sessions` ;
DROP TABLE IF EXISTS `view_run_unit_sessions`;
CREATE  OR REPLACE VIEW `view_run_unit_sessions` AS
SELECT 
			`survey_unit_sessions`.id AS session_id,
			`survey_unit_sessions`.created,
			`survey_unit_sessions`.ended,
			`survey_runs`.name AS run_name,
			`survey_runs`.id,
			`survey_runs`.user_id,
			`survey_run_units`.position,
			`survey_run_units`.unit_id,
			`survey_run_units`.run_id,
			`survey_units`.type
		
			 FROM `survey_unit_sessions`

 		LEFT JOIN `survey_units`
	 		ON `survey_unit_sessions`.unit_id = `survey_units`.id
 	
		LEFT JOIN `survey_run_units` 
	 		ON `survey_unit_sessions`.unit_id = `survey_run_units`.unit_id
		 
		LEFT JOIN `survey_runs`
		ON `survey_run_units`.run_id = `survey_runs`.id
;


SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
