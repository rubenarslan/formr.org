ALTER TABLE `survey_email_accounts` ADD COLUMN IF NOT EXISTS `deleted` INT(1) NOT NULL DEFAULT '0';
