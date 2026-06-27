-- Issue #608: configurable monthly compute limits, enforced per study admin
-- (run owner). When a user's current-calendar-month compute (summed across
-- all their runs, from survey_unit_sessions.execution_time) exceeds their
-- effective limit, ComputeLimitCron sets all their public runs non-public
-- (public=0) and reopens them when usage falls back under the limit (e.g.
-- the calendar month rolls over).
--
-- Effective limit = survey_users.compute_limit_monthly when not NULL, else
-- the instance-wide `compute_limit_monthly_default` config value. In both,
-- 0 means UNLIMITED; >0 is a budget in seconds. The default is 0 (infinite),
-- so nothing is limited until a superadmin sets a cap. Users cannot change
-- their own limit — it is superadmin-set only.
ALTER TABLE `survey_users`
    ADD COLUMN `compute_limit_monthly` DECIMAL(12,3) UNSIGNED NULL DEFAULT NULL
    COMMENT 'Monthly compute budget in seconds (issue #608). NULL=inherit global default; 0=unlimited; superadmin-set only';

-- When a run is auto-closed for exceeding the owner's monthly compute limit,
-- remember the public level it had so the cron can restore it on reopen.
-- NULL = the run was not closed by the compute limiter (so the limiter must
-- not touch its public state).
ALTER TABLE `survey_runs`
    ADD COLUMN `compute_closed_from` TINYINT NULL DEFAULT NULL
    COMMENT 'Prior public level if auto-closed by the monthly compute limiter (issue #608); NULL=not compute-closed';
