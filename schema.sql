SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='TRADITIONAL,ALLOW_INVALID_DATES';

CREATE SCHEMA IF NOT EXISTS `zwang` DEFAULT CHARACTER SET latin1 COLLATE latin1_swedish_ci ;
USE `zwang` ;

-- -----------------------------------------------------
-- Table `zwang`.`survey_users`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `email` VARCHAR(255) NULL ,
  `password` VARCHAR(255) NULL ,
  `user_code` CHAR(64) NOT NULL ,
  `email_verified` TINYINT(1) NULL DEFAULT 0 ,
  `email_token` VARCHAR(255) NULL ,
  `admin` TINYINT(1) NULL DEFAULT 0 ,
  PRIMARY KEY (`id`) )
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `zwang`.`survey_runs`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_runs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `owner_id` INT UNSIGNED NOT NULL ,
  `name` VARCHAR(45) NULL ,
  `api_secret` CHAR(64) NULL ,
  PRIMARY KEY (`id`) ,
  INDEX `fk_runs_survey_users1_idx` (`owner_id` ASC) ,
  CONSTRAINT `fk_runs_survey_users1`
    FOREIGN KEY (`owner_id` )
    REFERENCES `zwang`.`survey_users` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `zwang`.`survey_run_users`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_run_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `user_id` INT UNSIGNED NOT NULL ,
  `run_id` INT UNSIGNED NOT NULL ,
  `completed` TINYINT(1) NULL ,
  `admin` TINYINT(1) NULL DEFAULT 0 ,
  PRIMARY KEY (`id`) ,
  INDEX `fk_survey_users_studies_survey_users1_idx` (`user_id` ASC) ,
  INDEX `fk_survey_run_users_survey_runs1_idx` (`run_id` ASC) ,
  CONSTRAINT `fk_run_user234`
    FOREIGN KEY (`user_id` )
    REFERENCES `zwang`.`survey_users` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_run3439`
    FOREIGN KEY (`run_id` )
    REFERENCES `zwang`.`survey_runs` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `zwang`.`survey_units`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_units` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `type` VARCHAR(20) NULL ,
  PRIMARY KEY (`id`) )
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `zwang`.`survey_studies`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_studies` (
  `id` INT UNSIGNED NOT NULL ,
  `user_id` INT UNSIGNED NOT NULL ,
  `name` VARCHAR(255) NULL ,
  `logo_name` VARCHAR(255) NULL ,
  INDEX `fk_survey_studies_survey_users_idx` (`user_id` ASC) ,
  INDEX `fk_survey_studies_run_items1_idx` (`id` ASC) ,
  PRIMARY KEY (`id`) ,
  CONSTRAINT `fk_survey_studies_survey_users`
    FOREIGN KEY (`user_id` )
    REFERENCES `zwang`.`survey_users` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_study_unit`
    FOREIGN KEY (`id` )
    REFERENCES `zwang`.`survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `zwang`.`survey_run_units`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_run_units` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `run_id` INT UNSIGNED NOT NULL ,
  `unit_id` INT UNSIGNED NOT NULL ,
  `position` TINYINT NOT NULL ,
  PRIMARY KEY (`id`) ,
  INDEX `fk_survey_run_data_survey_runs1_idx` (`run_id` ASC) ,
  INDEX `fk_survey_run_data_survey_run_items1_idx` (`unit_id` ASC) ,
  CONSTRAINT `fk_suru`
    FOREIGN KEY (`run_id` )
    REFERENCES `zwang`.`survey_runs` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_suru_it`
    FOREIGN KEY (`unit_id` )
    REFERENCES `zwang`.`survey_units` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `zwang`.`survey_items`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_items` (
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
  INDEX `fk_survey_items_survey_studies1_idx` (`study_id` ASC) )
ENGINE = MyISAM
DEFAULT CHARACTER SET = utf8;


-- -----------------------------------------------------
-- Table `zwang`.`survey_items_display`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_items_display` (
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
  INDEX `session_item_views` (`study_id` ASC, `session_id` ASC, `item_id` ASC) )
ENGINE = MyISAM
DEFAULT CHARACTER SET = utf8;


-- -----------------------------------------------------
-- Table `zwang`.`survey_substitutions`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_substitutions` (
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
    REFERENCES `zwang`.`survey_studies` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `zwang`.`survey_unit_sessions`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_unit_sessions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `unit_id` INT UNSIGNED NOT NULL ,
  `created` DATETIME NOT NULL ,
  `session` CHAR(64) NULL ,
  `ended` DATETIME NULL DEFAULT NULL ,
  PRIMARY KEY (`id`) ,
  UNIQUE INDEX `session_uq` (`unit_id` ASC, `session` ASC) ,
  INDEX `fk_survey_sessions_survey_units1_idx` (`unit_id` ASC) ,
  CONSTRAINT `fk_survey_sessions_survey_units1`
    FOREIGN KEY (`unit_id` )
    REFERENCES `zwang`.`survey_units` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `zwang`.`survey_settings`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_settings` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT ,
  `study_id` INT UNSIGNED NOT NULL ,
  `key` VARCHAR(100) NULL DEFAULT NULL ,
  `value` TEXT NULL DEFAULT NULL ,
  PRIMARY KEY (`id`) ,
  UNIQUE INDEX `setting` (`study_id` ASC, `key` ASC) ,
  INDEX `fk_survey_settings_survey_studies1_idx` (`study_id` ASC) ,
  CONSTRAINT `fk_survey_settings_survey_studies1`
    FOREIGN KEY (`study_id` )
    REFERENCES `zwang`.`survey_studies` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION);


-- -----------------------------------------------------
-- Table `zwang`.`survey_email_accounts`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_email_accounts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT ,
  `user_id` INT UNSIGNED NOT NULL ,
  `from` VARCHAR(255) NULL ,
  `host` VARCHAR(255) NULL ,
  `port` SMALLINT NULL ,
  `tls` TINYINT NULL ,
  `username` VARCHAR(100) NULL ,
  `password` VARCHAR(255) NULL ,
  INDEX `fk_survey_emails_survey_users1_idx` (`user_id` ASC) ,
  PRIMARY KEY (`id`) ,
  CONSTRAINT `fk_email_user`
    FOREIGN KEY (`user_id` )
    REFERENCES `zwang`.`survey_users` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `zwang`.`survey_externals`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_externals` (
  `id` INT UNSIGNED NOT NULL ,
  `address` VARCHAR(255) NULL ,
  INDEX `fk_survey_forks_survey_run_items1_idx` (`id` ASC) ,
  PRIMARY KEY (`id`) ,
  CONSTRAINT `fk_external_unit`
    FOREIGN KEY (`id` )
    REFERENCES `zwang`.`survey_units` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `zwang`.`survey_pauses`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_pauses` (
  `id` INT UNSIGNED NOT NULL ,
  `wait_until_time` TIME NULL ,
  `wait_until_date` DATE NULL ,
  `wait_minutes` INT UNSIGNED NULL ,
  `relative_to` VARCHAR(255) NULL ,
  `message` TEXT NULL ,
  `message_parsed` TEXT NULL ,
  INDEX `fk_survey_breaks_survey_run_items1_idx` (`id` ASC) ,
  PRIMARY KEY (`id`) ,
  CONSTRAINT `fk_survey_breaks_survey_run_items1`
    FOREIGN KEY (`id` )
    REFERENCES `zwang`.`survey_units` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `zwang`.`survey_branches`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_branches` (
  `id` INT UNSIGNED NOT NULL ,
  `condition` VARCHAR(2000) NULL ,
  `if_true` TINYINT NULL ,
  `if_false` TINYINT NULL ,
  INDEX `fk_survey_branch_survey_units1_idx` (`id` ASC) ,
  PRIMARY KEY (`id`) ,
  CONSTRAINT `fk_branch_unit`
    FOREIGN KEY (`id` )
    REFERENCES `zwang`.`survey_units` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `zwang`.`survey_emails`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_emails` (
  `id` INT UNSIGNED NOT NULL ,
  `account_id` INT UNSIGNED NOT NULL ,
  `subject` VARCHAR(255) NULL ,
  `body` TEXT NULL ,
  `body_parsed` TEXT NULL ,
  `html` TINYINT(1) NULL ,
  INDEX `fk_survey_emails_survey_units1_idx` (`id` ASC) ,
  PRIMARY KEY (`id`) ,
  INDEX `fk_survey_emails_survey_email_accounts1_idx` (`account_id` ASC) ,
  CONSTRAINT `fk_email_unit`
    FOREIGN KEY (`id` )
    REFERENCES `zwang`.`survey_units` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_email_acc`
    FOREIGN KEY (`account_id` )
    REFERENCES `zwang`.`survey_email_accounts` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `zwang`.`survey_email_log`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_email_log` (
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
    REFERENCES `zwang`.`survey_emails` (`id` )
    ON DELETE CASCADE
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_email_log_survey_unit_sessions1`
    FOREIGN KEY (`session_id` )
    REFERENCES `zwang`.`survey_unit_sessions` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `zwang`.`survey_pages`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_pages` (
  `id` INT UNSIGNED NOT NULL ,
  `body` TEXT NULL ,
  `body_parsed` TEXT NULL ,
  `title` VARCHAR(255) NULL ,
  `end` TINYINT(1) NULL DEFAULT 1 ,
  INDEX `fk_survey_feedback_survey_units1_idx` (`id` ASC) ,
  PRIMARY KEY (`id`) ,
  CONSTRAINT `fk_page_unit`
    FOREIGN KEY (`id` )
    REFERENCES `zwang`.`survey_units` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Placeholder table for view `zwang`.`view_run_unit_sessions`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `zwang`.`view_run_unit_sessions` (`session_id` INT, `session` INT, `created` INT, `ended` INT, `run_name` INT, `id` INT, `owner_id` INT, `position` INT, `unit_id` INT, `run_id` INT, `type` INT);

-- -----------------------------------------------------
-- View `zwang`.`view_run_unit_sessions`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `zwang`.`view_run_unit_sessions`;
USE `zwang`;
CREATE  OR REPLACE VIEW `zwang`.`view_run_unit_sessions` AS
SELECT 
			`survey_unit_sessions`.id AS session_id,
			`survey_unit_sessions`.session,
			`survey_unit_sessions`.created,
			`survey_unit_sessions`.ended,
			`survey_runs`.name AS run_name,
			`survey_runs`.id,
			`survey_runs`.owner_id,
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
