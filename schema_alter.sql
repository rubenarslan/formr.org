SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='TRADITIONAL,ALLOW_INVALID_DATES';

ALTER TABLE `formr`.`survey_runs` ADD COLUMN `live` TINYINT(1) NULL DEFAULT 1  AFTER `public` , ADD COLUMN `locked` TINYINT(1) NULL DEFAULT 0  AFTER `live` , ADD COLUMN `overview_script` INT(10) UNSIGNED NULL DEFAULT NULL  AFTER `service_message` , ADD COLUMN `title` VARCHAR(255) NULL DEFAULT NULL  AFTER `display_service_message` , ADD COLUMN `description` VARCHAR(1000) NULL DEFAULT NULL  AFTER `title` , ADD COLUMN `public_blurb` TEXT NULL DEFAULT NULL  AFTER `description` , ADD COLUMN `header_image_path` VARCHAR(255) NULL DEFAULT NULL  AFTER `public_blurb` , ADD COLUMN `footer_text` TEXT NULL DEFAULT NULL  AFTER `header_image_path` , ADD COLUMN `custom_css_path` VARCHAR(255) NULL DEFAULT NULL  AFTER `footer_text` , ADD COLUMN `custom_js_path` VARCHAR(255) NULL DEFAULT NULL  AFTER `custom_css_path` , 
  ADD CONSTRAINT `fk_survey_runs_survey_units3`
  FOREIGN KEY (`overview_script` )
  REFERENCES `formr`.`survey_units` (`id` )
  ON DELETE NO ACTION
  ON UPDATE NO ACTION
, ADD INDEX `fk_survey_runs_survey_units3_idx` (`overview_script` ASC) ;

ALTER TABLE `formr`.`survey_items` ADD COLUMN `post_process` TEXT NULL DEFAULT NULL  AFTER `order` ;

ALTER TABLE `formr`.`survey_units` ADD COLUMN `description` VARCHAR(500) NULL DEFAULT NULL  AFTER `type` ;


SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
