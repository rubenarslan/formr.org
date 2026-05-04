-- SQL Patch 054: scope survey_r_call_results to the unit session.
--
-- Patch 052 keyed the cache on (call_id, args_hash) where args_hash is
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

TRUNCATE TABLE `survey_r_call_results`;

ALTER TABLE `survey_r_call_results`
    ADD COLUMN `unit_session_id` BIGINT UNSIGNED NOT NULL DEFAULT 0
        AFTER `call_id`,
    DROP INDEX `uq_call_args`,
    ADD UNIQUE KEY `uq_call_session_args` (`call_id`, `unit_session_id`, `args_hash`);
