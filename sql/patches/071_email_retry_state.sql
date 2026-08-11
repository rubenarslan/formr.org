-- v1.8.0 — retry state for the email queue.
--
-- NUMBERING WARNING (read before merging feature/form_v2 into this line):
-- 071 is the next free number on the RELEASE line (…069_write_time_metrics,
-- 070_compute_close_cron), but feature/form_v2 already ships a DIFFERENT
-- 071 (071_unit_session_layout.sql) and continues to 085. Atlas keys pending
-- work by version number and records applied versions in its ledger, so a
-- host that applied one branch's 071 will silently treat the other's as done
-- — here that means `tries`/`last_attempt` never get created and EmailQueue
-- fatals on "Unknown column 'tries'" at the first delivery failure.
-- Precedent for the fix direction is commit 8d60ae4e on feature/form_v2
-- ("reconcile form_v2 sql/patches numbering after the 1.7.0 merge"): the
-- release line owns the numbers and form_v2 renumbers to follow. Whoever
-- merges form_v2 next must renumber its 071–085 above this one and re-hash.
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
