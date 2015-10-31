ALTER TABLE `survey_run_sessions` 
ADD COLUMN `testing` TINYINT(1) NULL DEFAULT 0 AFTER `no_email`;

