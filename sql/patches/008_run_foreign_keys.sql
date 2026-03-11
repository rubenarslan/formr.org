-- Fix foreign keys (idempotent)
CALL formr_drop_foreign_key_if_exists('survey_email_log', 'fk_survey_email_log_survey_emails1');
CALL formr_drop_foreign_key_if_exists('survey_cron_log', 'fk_survey_cron_log_survey_runs1');
CALL formr_drop_foreign_key_if_exists('survey_uploaded_files', 'fk_survey_uploaded_files_survey_runs1');

CALL formr_add_foreign_key_if_not_exists('survey_email_log', 'fk_survey_email_log_survey_emails1', 'email_id', 'survey_emails', 'id', 'CASCADE', 'NO ACTION');
CALL formr_add_foreign_key_if_not_exists('survey_cron_log', 'fk_survey_cron_log_survey_runs1', 'run_id', 'survey_runs', 'id', 'CASCADE', 'NO ACTION');
CALL formr_add_foreign_key_if_not_exists('survey_uploaded_files', 'fk_survey_uploaded_files_survey_runs1', 'run_id', 'survey_runs', 'id', 'CASCADE', 'NO ACTION');
