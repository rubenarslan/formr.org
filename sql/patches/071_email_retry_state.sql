-- v1.8.0 — retry state for the email queue.
--
-- Before this, a delivery failure was terminal after two immediate SMTP
-- attempts: EmailQueue::sendEmail() retried once in-process and then wrote
-- STATUS_FAILED_TO_SEND (-2), which the pickup poll (status = 0) never
-- selects again. Nothing re-armed the row, nothing notified the study
-- admin, and the unit session had already ended `email_queued` and advanced
-- the run — so a 30-second SMTP blip silently dropped a study's email. The
-- `queue_item_ttl` / `queue_item_tries` settings were read into properties
-- and used nowhere, which is the tell that the retry was designed and never
-- wired.
--
-- `tries` counts delivery attempts so the queue can retry a transient
-- failure and give up deliberately (with an admin notification) instead of
-- by accident. `last_attempt` spaces those retries out and, together with
-- `created` + the TTL, bounds how long a row stays retryable.
ALTER TABLE `survey_email_log`
    ADD COLUMN `tries` tinyint(3) unsigned NOT NULL DEFAULT 0 AFTER `status`,
    ADD COLUMN `last_attempt` datetime DEFAULT NULL AFTER `tries`;

-- Serves the retry arm of the pickup poll:
--   status = -2 AND tries < :max AND (last_attempt IS NULL OR last_attempt < :cutoff)
-- Leading `status` keeps it usable for the plain status = 0 arm too.
ALTER TABLE `survey_email_log`
    ADD INDEX `idx_status_retry` (`status`, `tries`, `last_attempt`);
