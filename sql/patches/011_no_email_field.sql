ALTER TABLE `survey_run_sessions` CHANGE `no_email` `no_email` INT NULL DEFAULT NULL;
UPDATE `survey_run_sessions` SET `no_email` = NULL
