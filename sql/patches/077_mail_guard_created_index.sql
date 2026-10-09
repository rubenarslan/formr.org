-- 077: index survey_mail_guard_log by time alone.
--
-- Why: the admin summary (GROUP BY category, outcome over a time window) and
-- the retention prune (DELETE … WHERE created < cutoff) filter on `created`
-- only. 076 indexed `created` only as the trailing column of the recipient /
-- ip / subnet / (category, outcome) keys, so both scan the table. The log
-- takes one row per attempted send and is never pruned before this release,
-- so on a busy instance that is a full scan on every admin page view.
--
-- IF NOT EXISTS: replayable against a schema.sql that already carries it
-- (fresh installs are baselined at the highest patch without running them).

ALTER TABLE `survey_mail_guard_log`
  ADD INDEX IF NOT EXISTS `idx_mail_guard_created` (`created`);
