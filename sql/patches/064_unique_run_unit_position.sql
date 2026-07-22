-- 064_unique_run_unit_position.sql
-- Enforce run linearity at the schema level (run-engine audit F10, 2026-07).
--
-- A run is documented as a linear sequence of units ordered by position,
-- but `survey_run_units` only had a plain KEY `position_run (run_id,
-- position)` — nothing forbade two units at the same (run_id, position).
-- Traversal resolves a position via two independent, unordered LIMIT-1
-- lookups (RunSession::getUnitIdAtPosition / getRunUnitIdAtPosition), so
-- duplicate positions make "which unit is here" non-deterministic and can
-- even bind unit_id and run_unit_id from different placements. Reorder and
-- API import wrote positions blindly. Run::reorder() now validates in PHP;
-- this UNIQUE key is the storage-engine backstop so no path (import, raw
-- SQL, a future writer) can reintroduce the ambiguity.
--
-- Position <= 0 (audit F12) is guarded in PHP (Run::reorder rejects it and
-- the engine null-checks instead of truthiness); it is NOT expressible as
-- a UNIQUE/NOT NULL constraint here (position is smallint NOT NULL and 0 is
-- a valid smallint), so no CHECK is added — MariaDB CHECK support across
-- our supported versions is inconsistent and the PHP guard is authoritative.
--
-- PRECONDITION — this ALTER FAILS if any duplicate (run_id, position)
-- already exists. Find offenders first:
--     SELECT run_id, position, COUNT(*) c FROM survey_run_units
--     GROUP BY run_id, position HAVING c > 1;
-- diagnose_run_engine.sh check F10 reports the same. Renumber the
-- duplicates (admin run editor, or a targeted UPDATE) until that query is
-- empty BEFORE applying. Loud failure over dirty data is deliberate, as in
-- patch 063. On the audited prod instances only Muenster had 2 such rows.
--
-- Numbering: 064 is the next free slot on the 1.x line (highest on disk was
-- 063_unique_run_unit_iter). Reconcile against upstream sql/patches/ before
-- release — the OAuth-line numbering diverges (see 063's note).

ALTER TABLE `survey_run_units`
    DROP KEY `position_run`,
    ADD UNIQUE KEY `position_run` (`run_id`, `position`);
