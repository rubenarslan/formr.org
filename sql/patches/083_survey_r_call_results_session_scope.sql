-- SQL Patch 064: scope survey_r_call_results to the unit session.
--
-- Patch 062 keyed the cache on (call_id, args_hash) where args_hash is
-- sha256 of the client-supplied overlay answers. The R expression
-- evaluates against tail(survey_name, 1) — the *current participant's*
-- persisted row — so the same overlay yielded different results per
-- participant, but the cache stored under a key that didn't include the
-- participant. Two participants in the same study with colliding
-- overlays (empty overlays at page load are the canonical case, but
-- common multiple-choice answers collide too) would see participant A's
-- cached value returned to participant B. Confidentiality leak for
-- value/label slots and a correctness break for showif gating.
--
-- Fix: add unit_session_id to the row + the unique key. Unit-session id
-- is the right granularity because it changes when the participant
-- advances to a new Form unit (so cross-form cache pollution is also
-- prevented) and ends with the participant's session.
--
-- Existing rows are stale relative to the new key shape (cached against
-- the wrong identity), so TRUNCATE rather than backfill — the cache is
-- an optimization, not load-bearing data, and a cold cache fills in on
-- the next render.

-- Guards (IF [NOT] EXISTS) make this safe on hosts where the pre-rebase
-- phantom patch 054 already applied the same change: those re-run cleanly
-- instead of dying mid-file on a duplicate column. The MODIFY normalizes
-- the column to INT(10) UNSIGNED — the type of survey_unit_sessions.id —
-- so the cascade FK below is addable (054/early-064 used BIGINT).

TRUNCATE TABLE `survey_r_call_results`;

ALTER TABLE `survey_r_call_results`
    ADD COLUMN IF NOT EXISTS `unit_session_id` INT(10) UNSIGNED NOT NULL DEFAULT 0
        AFTER `call_id`;

ALTER TABLE `survey_r_call_results`
    MODIFY `unit_session_id` INT(10) UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE `survey_r_call_results`
    DROP INDEX IF EXISTS `uq_call_args`,
    ADD UNIQUE KEY IF NOT EXISTS `uq_call_session_args` (`call_id`, `unit_session_id`, `args_hash`);

-- Cache rows die with their unit session — this is also the eviction path
-- for ended/deleted sessions (cron handles age-based eviction of live ones).
ALTER TABLE `survey_r_call_results`
    ADD CONSTRAINT `fk_r_call_results_unit_session` FOREIGN KEY IF NOT EXISTS (`unit_session_id`)
        REFERENCES `survey_unit_sessions` (`id`) ON DELETE CASCADE;
