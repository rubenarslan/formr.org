ALTER TABLE survey_users
ADD 2fa_code varchar(16) DEFAULT '',
ADD backup_codes varchar(69) DEFAULT '';