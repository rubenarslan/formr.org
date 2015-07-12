SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='TRADITIONAL';

ALTER TABLE `survey_email_log` 
DROP FOREIGN KEY `fk_survey_email_log_survey_emails1`;

ALTER TABLE `survey_cron_log` 
DROP FOREIGN KEY `fk_survey_cron_log_survey_runs1`;

ALTER TABLE `survey_uploaded_files` 
DROP FOREIGN KEY `fk_survey_uploaded_files_survey_runs1`;

ALTER TABLE `survey_email_log` 
ADD CONSTRAINT `fk_survey_email_log_survey_emails1`
  FOREIGN KEY (`email_id`)
  REFERENCES `survey_emails` (`id`)
  ON DELETE CASCADE
  ON UPDATE NO ACTION;

ALTER TABLE `survey_cron_log` 
ADD CONSTRAINT `fk_survey_cron_log_survey_runs1`
  FOREIGN KEY (`run_id`)
  REFERENCES `survey_runs` (`id`)
  ON DELETE CASCADE
  ON UPDATE NO ACTION;

ALTER TABLE `survey_uploaded_files` 
ADD CONSTRAINT `fk_survey_uploaded_files_survey_runs1`
  FOREIGN KEY (`run_id`)
  REFERENCES `survey_runs` (`id`)
  ON DELETE CASCADE
  ON UPDATE NO ACTION;


SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
