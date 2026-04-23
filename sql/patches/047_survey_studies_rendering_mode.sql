-- SQL Patch 047: Add rendering_mode column to survey_studies
--
-- Phase 0 of form_v2 (see plan_form_v2.md §1 and §8): this column distinguishes
-- surveys rendered by the legacy Survey pipeline ('v1') from those rendered by
-- the new Form pipeline ('v2'). No runtime behavior changes in Phase 0 — the
-- Form RunUnit still delegates to the v1 renderer — but the flag lets later
-- phases branch their renderer choice without retrofitting existing data.

ALTER TABLE `survey_studies`
ADD COLUMN `rendering_mode` ENUM('v1', 'v2') NOT NULL DEFAULT 'v1' AFTER `use_paging`;
