-- Slow-query audit 2026-07: missing-index batch
-- (documentation/agent_doc/slow_query_audit_2026-07.md — SQ-04/05/07/08/09/14/15/24/32/35/38, SCH-03)

-- SQ-04/SQ-09: endLiveSessionsAtPlacement / replaceUnits UPDATEs — no index led with run_unit_id
-- SQ-07/SQ-08: getCurrentUnitSession / UnitSession::load filesort — no composite over the filter set
-- SQ-05: API poll endpoint ORDER BY (run_session_id, created, id) had no supporting index
ALTER TABLE `survey_unit_sessions`
  ADD INDEX `idx_run_unit_id_live` (`run_unit_id`, `ended`, `expired`),
  ADD INDEX `idx_current_lookup` (`run_session_id`, `unit_id`, `ended`, `expired`, `id`),
  ADD INDEX `idx_run_session_created_id` (`run_session_id`, `created`, `id`);

-- SQ-24: SessionResource pre-pagination ORDER BY created had only the bare run_id FK index
ALTER TABLE `survey_run_sessions`
  ADD INDEX `idx_run_created` (`run_id`, `created`);

-- SQ-14: push-log admin table ORDER BY created within a run
ALTER TABLE `push_logs`
  ADD INDEX `idx_run_created` (`run_id`, `created`);

-- SQ-15: mail-daemon poll (status=0 with unconstrained account_id)
-- SQ-35: per-send rate-limit check filtered a completely unindexed column
ALTER TABLE `survey_email_log`
  ADD INDEX `idx_status` (`status`),
  ADD INDEX `idx_recipient_status_created` (`recipient`, `status`, `created`);

-- SQ-38: Notification::canBeSent point lookup
ALTER TABLE `survey_notifications`
  ADD INDEX `idx_run_recipient_type` (`run_id`, `recipient_id`, `type`);

-- SQ-32/SCH-03: OAuthHelper::deleteClientRow full-scans; no index on client_id/user_id
ALTER TABLE `oauth_access_tokens`
  ADD INDEX `idx_client_id` (`client_id`),
  ADD INDEX `idx_user_id` (`user_id`);
ALTER TABLE `oauth_authorization_codes`
  ADD INDEX `idx_client_id` (`client_id`),
  ADD INDEX `idx_user_id` (`user_id`);
ALTER TABLE `oauth_refresh_tokens`
  ADD INDEX `idx_client_id` (`client_id`),
  ADD INDEX `idx_user_id` (`user_id`);
