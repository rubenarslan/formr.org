ALTER TABLE survey_users ADD COLUMN IF NOT EXISTS `2fa_code` varchar(255) DEFAULT '';
ALTER TABLE survey_users ADD COLUMN IF NOT EXISTS `backup_codes` varchar(255) DEFAULT '';
