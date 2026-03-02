-- Drop body_knit and add opencpu_url (idempotent)
CALL formr_drop_column_if_exists('survey_reports', 'body_knit');
ALTER TABLE `survey_reports` ADD COLUMN IF NOT EXISTS `opencpu_url` VARCHAR(400) NULL DEFAULT NULL;
