-- Track A (v0.26.0) — historical backfill of survey_unit_sessions.run_unit_id
-- and iteration. Forward writes since 047 already populate these for all
-- newly-created rows; this fills in the historical tail. Run once.
--
-- Fresh deployments (no historical rows) get a no-op execution.
--
-- See REFACTOR_QUEUE_PLAN.md A3a for the design rationale and edge cases.

-- Phase 1: unique-position case (the common one). When the same
-- (run_id, unit_id) maps to exactly one survey_run_units.id, we can
-- safely set survey_unit_sessions.run_unit_id from the JOIN. The HAVING
-- COUNT(*) = 1 subquery filters out runs where the same unit appears at
-- multiple positions — those need the multi-position phase below.
UPDATE `survey_unit_sessions` `us`
JOIN `survey_run_sessions` `rs` ON `rs`.`id` = `us`.`run_session_id`
JOIN `survey_run_units` `sru`
        ON `sru`.`run_id` = `rs`.`run_id`
       AND `sru`.`unit_id` = `us`.`unit_id`
JOIN (
    SELECT `run_id`, `unit_id`
    FROM `survey_run_units`
    GROUP BY `run_id`, `unit_id`
    HAVING COUNT(*) = 1
) `uniq` ON `uniq`.`run_id` = `sru`.`run_id`
        AND `uniq`.`unit_id` = `sru`.`unit_id`
SET `us`.`run_unit_id` = `sru`.`id`
WHERE `us`.`run_unit_id` IS NULL;

-- Phase 2: iteration. Best-effort by historical (run_session_id, unit_id)
-- partitioning ordered by id (proxy for created-at). Production
-- post-A2 rows already carry the correct iteration computed by
-- UnitSession::create — we leave those alone and only fill rows with
-- iteration IS NULL. Pre-A2 rows have iteration NULL by definition
-- (the column didn't exist before 047).
--
-- For multi-position-reuse runs this counts iterations across all
-- positions of the same unit, which is the same model that legacy
-- analysis tooling has always implicitly used (since it had no
-- per-position discriminator either). The backfill report at the bottom
-- surfaces unresolved cases for analyst review.
UPDATE `survey_unit_sessions` `us`
JOIN (
    SELECT `id`,
           ROW_NUMBER() OVER (PARTITION BY `run_session_id`, `unit_id`
                              ORDER BY `id`) AS `rn`
    FROM `survey_unit_sessions`
) `ranked` ON `ranked`.`id` = `us`.`id`
SET `us`.`iteration` = `ranked`.`rn`
WHERE `us`.`iteration` IS NULL;

-- Phase 3: flag the multi-position-reuse rows that stayed NULL after
-- phase 1 so analysts know the run_unit_id is genuinely ambiguous and
-- not just a missed backfill. JSON shape is documented in the doc-block
-- of UnitSession::logResult (state_log column).
UPDATE `survey_unit_sessions` `us`
SET `us`.`state_log` = JSON_OBJECT(
        'backfill', 'run_unit_id_ambiguous',
        'reason',   'multi_position_reuse'
    )
WHERE `us`.`run_unit_id` IS NULL;
