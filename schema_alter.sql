SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='TRADITIONAL';

ALTER TABLE `formr`.`survey_users` 
ADD COLUMN `created` DATETIME NULL DEFAULT NULL AFTER `user_code`,
ADD COLUMN `modified` DATETIME NULL DEFAULT NULL AFTER `created`,
ADD COLUMN `mobile_number` VARCHAR(30) NULL DEFAULT NULL AFTER `reset_token_expiry`,
ADD COLUMN `mobile_verification_hash` VARCHAR(255) NULL DEFAULT NULL AFTER `mobile_number`,
ADD COLUMN `mobile_verified` TINYINT(1) NULL DEFAULT 0 AFTER `mobile_verification_hash`;

ALTER TABLE `formr`.`survey_studies` 
DROP COLUMN `logo_name`,
ADD COLUMN `created` DATETIME NULL DEFAULT NULL AFTER `user_id`,
ADD COLUMN `modified` DATETIME NULL DEFAULT NULL AFTER `created`,
ADD COLUMN `valid` TINYINT(1) NULL DEFAULT NULL AFTER `name`,
ADD COLUMN `maximum_number_displayed` SMALLINT(5) UNSIGNED NULL DEFAULT NULL AFTER `valid`,
ADD COLUMN `displayed_percentage_maximum` TINYINT(3) UNSIGNED NULL DEFAULT NULL AFTER `maximum_number_displayed`,
ADD COLUMN `add_percentage_points` TINYINT(4) NULL DEFAULT NULL AFTER `displayed_percentage_maximum`;

ALTER TABLE `formr`.`survey_runs` 
DROP COLUMN `display_service_message`,
CHANGE COLUMN `public` `public` TINYINT(4) NULL DEFAULT 0 ,
ADD COLUMN `created` DATETIME NULL DEFAULT NULL AFTER `user_id`,
ADD COLUMN `modified` DATETIME NULL DEFAULT NULL AFTER `created`,
ADD COLUMN `locked` TINYINT(1) NULL DEFAULT 0 AFTER `public`,
ADD COLUMN `overview_script` INT(10) UNSIGNED NULL DEFAULT NULL AFTER `service_message`,
ADD COLUMN `deactivated_page` INT(10) UNSIGNED NULL DEFAULT NULL AFTER `overview_script`,
ADD COLUMN `title` VARCHAR(255) NULL DEFAULT NULL AFTER `deactivated_page`,
ADD COLUMN `description` VARCHAR(1000) NULL DEFAULT NULL AFTER `title`,
ADD COLUMN `description_parsed` TEXT NULL DEFAULT NULL AFTER `description`,
ADD COLUMN `public_blurb` TEXT NULL DEFAULT NULL AFTER `description_parsed`,
ADD COLUMN `public_blurb_parsed` TEXT NULL DEFAULT NULL AFTER `public_blurb`,
ADD COLUMN `header_image_path` VARCHAR(255) NULL DEFAULT NULL AFTER `public_blurb_parsed`,
ADD COLUMN `footer_text` TEXT NULL DEFAULT NULL AFTER `header_image_path`,
ADD COLUMN `footer_text_parsed` TEXT NULL DEFAULT NULL AFTER `footer_text`,
ADD COLUMN `custom_css_path` VARCHAR(255) NULL DEFAULT NULL AFTER `footer_text_parsed`,
ADD COLUMN `custom_js_path` VARCHAR(255) NULL DEFAULT NULL AFTER `custom_css_path`,
ADD INDEX `fk_survey_runs_survey_units3_idx` (`overview_script` ASC),
ADD INDEX `fk_survey_runs_survey_units4_idx` (`deactivated_page` ASC);

ALTER TABLE `formr`.`survey_run_units` 
ADD COLUMN `description` VARCHAR(500) NULL DEFAULT NULL AFTER `position`;

ALTER TABLE `formr`.`survey_items` 
ADD COLUMN `post_process` TEXT NULL DEFAULT NULL AFTER `order`;

ALTER TABLE `formr`.`survey_units` 
CHANGE COLUMN `type` `type` VARCHAR(20) NULL DEFAULT NULL AFTER `modified`;

ALTER TABLE `formr`.`survey_email_accounts` 
ADD COLUMN `created` DATETIME NULL DEFAULT NULL AFTER `user_id`,
ADD COLUMN `modified` DATETIME NULL DEFAULT NULL AFTER `created`;

ALTER TABLE `formr`.`survey_results` 
CHANGE COLUMN `created` `created` DATETIME NULL DEFAULT NULL AFTER `study_id`;

ALTER TABLE `formr`.`survey_run_sessions` 
ADD COLUMN `deactivated` TINYINT(1) NULL DEFAULT 0 AFTER `current_unit_id`,
ADD COLUMN `no_email` TINYINT(1) NULL DEFAULT 0 AFTER `deactivated`;

DROP TABLE IF EXISTS `formr`.`survey_settings` ;

ALTER TABLE `formr`.`survey_runs` 
ADD CONSTRAINT `fk_survey_runs_survey_units3`
  FOREIGN KEY (`overview_script`)
  REFERENCES `formr`.`survey_units` (`id`)
  ON DELETE NO ACTION
  ON UPDATE NO ACTION,
ADD CONSTRAINT `fk_survey_runs_survey_units4`
  FOREIGN KEY (`deactivated_page`)
  REFERENCES `formr`.`survey_units` (`id`)
  ON DELETE NO ACTION
  ON UPDATE NO ACTION;


SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
