-- Slow-query audit 2026-07: schema housekeeping
-- (documentation/agent_doc/slow_query_audit_2026-07.md — SCH-01/02/05/06)
-- Every dropped index is duplicate-of-PK, a left prefix of a surviving composite,
-- or (last block) made redundant by 065's new composites. FK columns keep a
-- covering leftmost index in all cases.

-- SCH-01: redundant single-column FK indexes duplicated by composites/PK
ALTER TABLE `push_messages`        DROP INDEX IF EXISTS `fk_push_messages_units_idx`;
ALTER TABLE `survey_branches`      DROP INDEX IF EXISTS `fk_survey_branch_survey_units1_idx`;
ALTER TABLE `survey_emails`        DROP INDEX IF EXISTS `fk_survey_emails_survey_units1_idx`;
ALTER TABLE `survey_externals`     DROP INDEX IF EXISTS `fk_survey_forks_survey_run_items1_idx`;
ALTER TABLE `survey_pages`         DROP INDEX IF EXISTS `fk_survey_feedback_survey_units1_idx`;
ALTER TABLE `survey_pauses`        DROP INDEX IF EXISTS `fk_survey_breaks_survey_run_items1_idx`;
ALTER TABLE `survey_shuffles`      DROP INDEX IF EXISTS `fk_survey_branch_survey_units1_idx`;
ALTER TABLE `survey_studies`       DROP INDEX IF EXISTS `fk_survey_studies_run_items1_idx`;
ALTER TABLE `survey_text_messages` DROP INDEX IF EXISTS `fk_survey_emails_survey_units1_idx`;
ALTER TABLE `shuffle`              DROP INDEX IF EXISTS `fk_survey_results_survey_unit_sessions1_idx`;
ALTER TABLE `survey_reports`       DROP INDEX IF EXISTS `fk_survey_results_survey_unit_sessions1_idx`;
ALTER TABLE `survey_results`       DROP INDEX IF EXISTS `fk_survey_results_survey_unit_sessions1_idx`;
ALTER TABLE `survey_items`                 DROP INDEX IF EXISTS `fk_survey_items_survey_studies1_idx`;
-- `survey_resource_survey_sizes` is deliberately NOT touched here. It reached
-- fresh installs through schema.sql but no patch ever created it, so every
-- instance seeded before it was added lacks the table — and no IF EXISTS can
-- save an ALTER whose TABLE is missing (Error 1146). This one line halted the
-- 1.7.0 rollout on every long-lived instance. Nothing in the app references
-- the table, so its redundant `user_id` index is not worth a landmine; if it
-- is ever cleaned up, do it in a patch that CREATEs the table first.
ALTER TABLE `survey_run_secrets`           DROP INDEX IF EXISTS `run_id`;
ALTER TABLE `survey_run_sessions`          DROP INDEX IF EXISTS `fk_survey_run_sessions_survey_users1_idx`;
ALTER TABLE `survey_run_units`             DROP INDEX IF EXISTS `fk_survey_run_data_survey_runs1_idx`;
ALTER TABLE `survey_studies`               DROP INDEX IF EXISTS `fk_survey_studies_survey_users_idx`;
ALTER TABLE `survey_unit_sessions`         DROP INDEX IF EXISTS `fk_survey_unit_sessions_survey_run_sessions1_idx`;
ALTER TABLE `survey_uploaded_files`        DROP INDEX IF EXISTS `fk_survey_uploaded_files_survey_runs1_idx`;
ALTER TABLE `survey_item_choices` DROP INDEX IF EXISTS `listname`;
ALTER TABLE `survey_item_choices` DROP INDEX IF EXISTS `list_name_2`;

-- Made redundant by 065's new (col, ...) composites (left-prefix coverage, FKs included)
ALTER TABLE `survey_run_sessions`  DROP INDEX IF EXISTS `fk_survey_run_sessions_survey_runs1_idx`;
ALTER TABLE `push_logs`            DROP INDEX IF EXISTS `fk_push_logs_runs_idx`;
ALTER TABLE `survey_notifications` DROP INDEX IF EXISTS `run_id`;

-- SCH-05: 15 distinct values across 92k rows; no code path filters position standalone
ALTER TABLE `survey_run_sessions` DROP INDEX IF EXISTS `position`;

-- SCH-02: oauth_scopes had no PRIMARY KEY (hidden DB_ROW_ID clustered index)
ALTER TABLE `oauth_scopes`
  ADD COLUMN IF NOT EXISTS `id` INT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
  ADD PRIMARY KEY IF NOT EXISTS (`id`);

-- SCH-06: last two MyISAM tables → InnoDB (crash safety, row locking)
ALTER TABLE `osf` ENGINE=InnoDB;
ALTER TABLE `survey_run_settings` ENGINE=InnoDB;
