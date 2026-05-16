-- Track A (v0.26.0) — strictly-additive columns on survey_unit_sessions to
-- (1) disambiguate unit-session rows when the same unit is reused at
--     multiple positions in a run (D1: run_unit_id),
-- (2) explicitly count back-jump / SkipBackward / loop iterations
--     (D2: iteration),
-- (3) make the queue's four magic `queued` values self-documenting
--     (D3: state ENUM, dual-written alongside `queued`),
-- (4) provide structured logging alongside the unstructured result_log
--     (D5: state_log JSON),
-- (5) close R5 (daemon-kill mid-cascade orphan re-send) by adding a
--     unique idempotency_key column for forward writes from
--     UnitSession::create / Email::queueNow / PushMessage::send.
--
-- All NULL/default. The plan and tests/REFACTOR_QUEUE_PLAN.md describe
-- intent. Atlas down() is the inverse — DROP COLUMN / DROP KEY of every
-- addition, no data loss for legacy rows because they remain NULL.

ALTER TABLE `survey_unit_sessions`
    ADD COLUMN `run_unit_id`     INT(10) UNSIGNED NULL DEFAULT NULL AFTER `unit_id`,
    ADD COLUMN `iteration`       INT(10) UNSIGNED NULL DEFAULT NULL AFTER `run_unit_id`,
    ADD COLUMN `state`           ENUM(
                                     'PENDING',
                                     'RUNNING',
                                     'WAITING_USER',
                                     'WAITING_TIMER',
                                     'ENDED',
                                     'EXPIRED',
                                     'SUPERSEDED'
                                 ) NULL DEFAULT NULL AFTER `expired`,
    ADD COLUMN `state_log`       LONGTEXT NULL DEFAULT NULL
                                 CHECK (`state_log` IS NULL OR JSON_VALID(`state_log`))
                                 AFTER `state`,
    ADD COLUMN `idempotency_key` VARCHAR(128) NULL DEFAULT NULL AFTER `state_log`,
    ADD UNIQUE KEY `idemp_unit_session` (`idempotency_key`),
    ADD KEY `idx_run_unit_iter` (`run_session_id`, `run_unit_id`, `iteration`),
    ADD KEY `idx_state` (`state`);

-- Email-log idempotency: closes the daemon-kill mid-cascade re-send case
-- (R5). Forward writes in Email::queueNow set
-- idempotency_key = "email:{unit_session_id}:{email_id}" and use
-- INSERT ... ON DUPLICATE KEY UPDATE id=id so the second attempt is a
-- silent no-op. Legacy rows stay NULL (UNIQUE permits multiple NULLs in
-- MariaDB) and fall back to the v0.25.7 in-PHP terminal-result guard.
ALTER TABLE `survey_email_log`
    ADD COLUMN `idempotency_key` VARCHAR(128) NULL DEFAULT NULL AFTER `sent`,
    ADD UNIQUE KEY `idemp_email_log` (`idempotency_key`);

-- Push-log idempotency: same shape as email_log. push_logs already
-- exists (migration 044); we add only the idempotency_key + UNIQUE.
-- Forward writes in PushMessage::getUnitSessionOutput set
-- idempotency_key = "push:{unit_session_id}" before invoking the WebPush
-- transport; the duplicate INSERT no-ops on a SIGKILL-restart attempt.
ALTER TABLE `push_logs`
    ADD COLUMN `idempotency_key` VARCHAR(128) NULL DEFAULT NULL AFTER `created`,
    ADD UNIQUE KEY `idemp_push_log` (`idempotency_key`);

-- Note: no FK from survey_unit_sessions.run_unit_id to survey_run_units(id).
-- survey_run_units' PK is composite (id, run_id) — InnoDB would accept the
-- FK against the leftmost prefix, but the constraint would not enforce
-- uniqueness in the strict sense, so it offers little protection beyond
-- the application-level helper RunSession::getRunUnitIdAtPosition().
-- Skipping the FK keeps the migration reversible without tangled
-- ON DELETE cascade behaviour (the existing fk_survey_unit_sessions_run_session
-- already wipes us on run-session deletion, which transitively covers
-- run_unit deletions in practice).
