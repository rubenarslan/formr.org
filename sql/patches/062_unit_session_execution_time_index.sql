-- Issue #608: make the compute-usage dashboards scale on large instances.
--
-- The dashboards aggregate survey_unit_sessions.execution_time grouped by run
-- and user. With only the run_session_id FK index, the join reads every matched
-- row and checks `execution_time IS NOT NULL` afterwards (EXPLAIN: "Using
-- where") — i.e. it scans the whole table to find the measured fraction, with
-- random row IO. On a big instance that is very slow.
--
-- This covering index lets the per-run_session probe be index-only AND range
-- past the NULL-execution_time entries, so the dashboards touch only the rows
-- that actually carry a measured time. Column order:
--   run_session_id  -> the join key (driven from runs -> run_sessions)
--   execution_time  -> the NOT NULL filter + SUM/AVG/MAX live here
--   created         -> covers MAX(created) / the "this month" CASE
-- (id is the PK, implicitly present in the InnoDB secondary-index leaf, so
--  COUNT(id) is covered too.)
ALTER TABLE `survey_unit_sessions`
    ADD INDEX `idx_uxec_compute` (`run_session_id`, `execution_time`, `created`);
