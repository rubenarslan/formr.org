-- v1.8.0 — throttle admin notifications per unit, not per run, and record
-- what the throttle swallows (X3/X4 in LOG_STATE_AUDIT.md).
--
-- `canBeSent` used to key on run + recipient + type, so every error source
-- in a run shared one 10-minute email slot: during an incident the admin
-- heard about whichever unit failed first, and every distinct problem in
-- the window vanished without trace (a suppressed notification was not
-- logged anywhere).
--
-- fingerprint: "<type>:u<survey_units.id>" — one throttle bucket per unit
--   and notification type. Legacy rows keep NULL and are no longer
--   consulted by the throttle, so at most one extra email per unit can go
--   out in the first window after upgrading.
-- suppressed: 1 = throttled away; recorded so the next delivered email can
--   carry a "N similar notifications were suppressed" digest and so
--   silence is distinguishable from "no errors" after the fact. Suppressed
--   rows do not restart the throttle window.
--
-- IF NOT EXISTS guards: replayable against a schema.sql that already
-- carries the columns (fresh installs are baselined at the highest patch
-- without running them; see 073's header for the convention).
ALTER TABLE `survey_notifications`
  ADD COLUMN IF NOT EXISTS `fingerprint` varchar(64) DEFAULT NULL AFTER `recipient_id`,
  ADD COLUMN IF NOT EXISTS `suppressed` tinyint(1) NOT NULL DEFAULT 0 AFTER `fingerprint`;

ALTER TABLE `survey_notifications`
  ADD INDEX IF NOT EXISTS `idx_run_recipient_fingerprint` (`run_id`, `recipient_id`, `fingerprint`);
