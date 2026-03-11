ALTER TABLE `survey_email_accounts` ADD COLUMN IF NOT EXISTS `auth_key` TEXT NOT NULL DEFAULT '';
