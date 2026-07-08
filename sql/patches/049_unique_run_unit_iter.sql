-- 049_unique_run_unit_iter.sql
-- Prevent duplicate unit-sessions forever (v0.27.0, L2).
--
-- UnitSession::create() assigns identity as (run_session_id, run_unit_id,
-- iteration), where iteration = COALESCE(MAX(iteration),0)+1 is READ and
-- then INSERTed non-atomically. Two concurrent creates for the same
-- placement read the same MAX and INSERT the same tuple -> duplicate rows
-- ("repeated pause 201"). Patch 047 added `idx_run_unit_iter` as a plain
-- KEY, which does NOT stop this. Promoting it to UNIQUE makes the storage
-- engine reject the second insert; UnitSession::create() catches the 23000
-- and adopts the winner's row (idempotent create), so the losing request
-- rides the winner instead of erroring.
--
-- NULL-safe: MySQL/MariaDB UNIQUE permits multiple NULLs (the same property
-- patch 047 relies on for idempotency_key). Legacy rows with NULL
-- run_unit_id / iteration (pre-047; the 048 backfill intentionally left
-- multi-position rows NULL) stay unconstrained — only fully-populated NEW
-- rows are enforced, which is exactly the intent.
--
-- PRECONDITION — this ALTER FAILS if any duplicate tuple already exists.
-- Run  bin/heal_duplicate_pause_sessions.php --apply  (and resolve every
-- flagged review cluster) until it reports
--   "Remaining active duplicate tuples (run_unit_id path): 0"
-- BEFORE applying this migration. That is deliberate: a silent constraint
-- over dirty data would be worse than a loud failure.
--
-- NOTE — 049 is the next free slot on THIS branch (highest on disk was
-- 048). Migration numbers are coordinated with upstream and have collided
-- across branches before; reconcile the number against formr.org's
-- sql/patches/ before release.

ALTER TABLE `survey_unit_sessions`
    DROP KEY `idx_run_unit_iter`,
    ADD UNIQUE KEY `idx_run_unit_iter` (`run_session_id`, `run_unit_id`, `iteration`);
