SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='TRADITIONAL,ALLOW_INVALID_DATES';

CREATE SCHEMA IF NOT EXISTS `zwang` DEFAULT CHARACTER SET latin1 COLLATE latin1_swedish_ci ;
USE `zwang` ;

-- -----------------------------------------------------
-- Table `zwang`.`survey_users`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_users` (
  `id` INT UNSIGNED NOT NULL ,
  `email` VARCHAR(255) NULL ,
  `password` VARCHAR(255) NULL ,
  `vpncode` VARCHAR(255) NULL ,
  `email_verified` TINYINT(1) NULL DEFAULT 0 ,
  `email_token` VARCHAR(255) NULL ,
  `admin` TINYINT(1) NULL DEFAULT 0 ,
  PRIMARY KEY (`id`) )
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `zwang`.`survey_studies`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_studies` (
  `id` INT UNSIGNED NOT NULL ,
  `user_id` INT UNSIGNED NOT NULL ,
  `name` VARCHAR(255) NULL ,
  `prefix` VARCHAR(45) NULL ,
  `registered_req` TINYINT(1) NULL ,
  `email_req` TINYINT(1) NULL ,
  `bday_req` TINYINT(1) NULL ,
  `public` TINYINT(1) NULL ,
  `logo_name` VARCHAR(255) NULL ,
  PRIMARY KEY (`id`) ,
  INDEX `fk_survey_studies_survey_users_idx` (`user_id` ASC) ,
  CONSTRAINT `fk_survey_studies_survey_users`
    FOREIGN KEY (`user_id` )
    REFERENCES `zwang`.`survey_users` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `zwang`.`survey_users_studies`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_users_studies` (
  `id` INT UNSIGNED NOT NULL ,
  `study_id` INT UNSIGNED NOT NULL ,
  `user_id` INT UNSIGNED NOT NULL ,
  `completed` TINYINT(1) NULL ,
  PRIMARY KEY (`id`) ,
  INDEX `fk_survey_users_studies_survey_studies1_idx` (`study_id` ASC) ,
  INDEX `fk_survey_users_studies_survey_users1_idx` (`user_id` ASC) ,
  CONSTRAINT `fk_survey_users_studies_survey_studies1`
    FOREIGN KEY (`study_id` )
    REFERENCES `zwang`.`survey_studies` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_users_studies_survey_users1`
    FOREIGN KEY (`user_id` )
    REFERENCES `zwang`.`survey_users` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `zwang`.`survey_runs`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_runs` (
  `id` INT UNSIGNED NOT NULL ,
  `user_id` INT UNSIGNED NOT NULL ,
  `name` VARCHAR(45) NULL ,
  `public` VARCHAR(45) NULL ,
  `registered_req` VARCHAR(45) NULL ,
  PRIMARY KEY (`id`) ,
  INDEX `fk_runs_survey_users1_idx` (`user_id` ASC) ,
  CONSTRAINT `fk_runs_survey_users1`
    FOREIGN KEY (`user_id` )
    REFERENCES `zwang`.`survey_users` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `zwang`.`survey_run_data`
-- -----------------------------------------------------
CREATE  TABLE IF NOT EXISTS `zwang`.`survey_run_data` (
  `id` INT NOT NULL ,
  `run_id` INT UNSIGNED NOT NULL ,
  `study_id` INT UNSIGNED NOT NULL ,
  `position` VARCHAR(45) NULL ,
  `optional` VARCHAR(45) NULL ,
  PRIMARY KEY (`id`) ,
  INDEX `fk_survey_run_data_survey_runs1_idx` (`run_id` ASC) ,
  INDEX `fk_survey_run_data_survey_studies1_idx` (`study_id` ASC) ,
  CONSTRAINT `fk_survey_run_data_survey_runs1`
    FOREIGN KEY (`run_id` )
    REFERENCES `zwang`.`survey_runs` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_survey_run_data_survey_studies1`
    FOREIGN KEY (`study_id` )
    REFERENCES `zwang`.`survey_studies` (`id` )
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;



SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
