ALTER TABLE survey_users
ADD 2fa_code varchar(255) DEFAULT '',
ADD backup_codes varchar(255) DEFAULT '';
