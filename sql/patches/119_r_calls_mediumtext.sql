-- SQL Patch 119: survey_r_calls.expr and survey_r_call_results.result_json
-- as MEDIUMTEXT.
--
-- A form_v2 page records each dynamic label and note_iframe source, whole,
-- as an allowlisted R call (survey_r_calls.expr, patch 102) and caches what
-- it knits (survey_r_call_results.result_json, patch 105). Both were TEXT
-- (64 KiB) while survey_items.label is MEDIUMTEXT: under STRICT_ALL_TABLES a
-- longer label (an R Markdown feedback page with inline CSS or an image)
-- made the allowlist INSERT fail, so the form could not render at all, and
-- a longer knitted result was never cached.
--
-- Drift-tolerant: MODIFY to the column's current definition is a no-op.

ALTER TABLE `survey_r_calls` MODIFY `expr` MEDIUMTEXT NOT NULL;
ALTER TABLE `survey_r_call_results`
  MODIFY `result_json` MEDIUMTEXT NOT NULL COMMENT 'JSON-encoded {result: ...} payload';
