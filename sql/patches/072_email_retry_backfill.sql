-- v1.8.0 — one-shot backfill for 071_email_retry_state.sql.
--
-- 071 made STATUS_FAILED_TO_SEND (-2) retryable and added `tries` /
-- `last_attempt`. Both columns land with their DEFAULTs on every existing
-- row: tries = 0, last_attempt = NULL. EmailQueue::pickupWhere()'s retry
-- arm is
--
--   status = -2 AND tries < :queue_item_tries
--             AND (last_attempt IS NULL OR last_attempt < NOW() - INTERVAL n SECOND)
--
-- so on an install with any delivery history EVERY row that has ever
-- failed matches it — SMTP blips, dead addresses, emails from studies
-- that closed years ago. The first daemon pass after the migration would
-- deliver all of them. isGivingUp()'s `created`/TTL arm does not save us:
-- it is evaluated AFTER $mailer->send() in sendEmail(), so it can stop the
-- SECOND attempt but never the first, and pickupWhere() puts no `created`
-- bound in the SQL.
--
-- Retiring the historic rows is the right call rather than bounding the
-- poll on `created`, because those rows failed under the OLD semantics
-- where -2 was terminal: the run has long since moved on, the study may
-- be closed, and re-delivering is worse than not delivering.
--
-- `last_attempt IS NULL` identifies them exactly. countAttempt()
-- (EmailQueue.php) is called before every SMTP try and stamps
-- last_attempt = NOW(), and sendEmail() is the only writer of status = -2
-- (the inline claimSyncSend() path DELETEs its STATUS_SENDING row on
-- failure instead), so any row that failed under the new code already has
-- a non-NULL last_attempt and is left alone with its real budget intact.
--
-- tries = 255 is the tinyint(3) unsigned maximum, used here as an
-- "exhausted, do not retry" sentinel: it fails `tries < :queue_item_tries`
-- for any sane configured budget. Paired with last_attempt IS NULL it
-- reads unambiguously in the table as "pre-migration failure, never
-- attempted under the retry counter".
UPDATE `survey_email_log`
SET `tries` = 255
WHERE `status` = -2
  AND `last_attempt` IS NULL;
