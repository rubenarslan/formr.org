-- Create the `text_solo` e2e fixture: a tiny, TEXT-ONLY solo v2 study for the
-- BrowserStack device/screenshot tests. all_widgets is OpenCPU-heavy (feedback
-- plots, plotly note_iframe, R values/showifs); rendering it repeatedly on a
-- resource-limited dev box can crash OpenCPU mid-run and the device screenshots
-- then show "problem evaluating showifs" banners. This study has only plain text
-- inputs (no showif/value/label R) so it makes ZERO OpenCPU calls.
--
-- Built by cloning e2e-aw-v2 (so the run/Form/results-table plumbing is correct)
-- then trimming the item set to a handful of text inputs + a submit. One-time,
-- host-side; the wrapper (create-text-fixture.sh) skips if it already exists.

SET @src_run    := (SELECT id FROM survey_runs WHERE name = 'e2e-aw-v2' LIMIT 1);
SET @old_form   := (SELECT u.id FROM survey_units u JOIN survey_run_units ru ON ru.unit_id = u.id WHERE ru.run_id = @src_run AND u.type = 'Form' LIMIT 1);
SET @old_page   := (SELECT u.id FROM survey_units u JOIN survey_run_units ru ON ru.unit_id = u.id WHERE ru.run_id = @src_run AND u.type = 'Page' LIMIT 1);
SET @src_study  := @old_form; -- a Form unit's id is its study's id

-- study-anchor unit first (survey_studies.id is FK'd to survey_units.id)
CREATE TEMPORARY TABLE t AS SELECT * FROM survey_units WHERE id = @src_study;
UPDATE t SET id = 0; INSERT INTO survey_units SELECT * FROM t;
SET @new_study := LAST_INSERT_ID();
DROP TEMPORARY TABLE t;

-- study (id = anchor, renamed, solo, v2)
CREATE TEMPORARY TABLE t AS SELECT * FROM survey_studies WHERE id = @src_study;
UPDATE t SET id = @new_study, name = 'e2e_text_solo_v2', layout = 'solo', rendering_mode = 'v2';
INSERT INTO survey_studies SELECT * FROM t;
DROP TEMPORARY TABLE t;

-- dedicated results table (never share the source study's; see create-solo-fixture.sql)
SET @src_rt := (SELECT results_table FROM survey_studies WHERE id = @src_study);
SET @new_rt := CONCAT('s', @new_study, '_text_solo');
SET @ddl    := CONCAT('CREATE TABLE `', @new_rt, '` LIKE `', @src_rt, '`');
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE survey_studies SET results_table = @new_rt, last_iteration = 0 WHERE id = @new_study;

-- items: clone all, then keep ONLY plain text inputs + a submit (no R anywhere,
-- so the v2 render makes no OpenCPU showif/value/label calls).
CREATE TEMPORARY TABLE t AS SELECT * FROM survey_items WHERE study_id = @src_study;
UPDATE t SET id = 0, study_id = @new_study;
INSERT INTO survey_items SELECT * FROM t;
DROP TEMPORARY TABLE t;
DELETE FROM survey_items WHERE study_id = @new_study
  AND name NOT IN ('abode', 'yourstory', 'your_email', 'teeth', 'submit');
-- (text items have no choices, so none are cloned)

-- Form unit: a Form unit IS its study (the anchor row cloned above)
SET @new_form := @new_study;

-- trailing Page unit (verbatim)
CREATE TEMPORARY TABLE t AS SELECT * FROM survey_units WHERE id = @old_page;
UPDATE t SET id = 0; INSERT INTO survey_units SELECT * FROM t;
SET @new_page := LAST_INSERT_ID();
DROP TEMPORARY TABLE t;

-- run
CREATE TEMPORARY TABLE t AS SELECT * FROM survey_runs WHERE id = @src_run;
UPDATE t SET id = 0, name = 'e2e-text-solo-v2'; INSERT INTO survey_runs SELECT * FROM t;
SET @new_run := LAST_INSERT_ID();
DROP TEMPORARY TABLE t;

-- run units (remap unit ids)
CREATE TEMPORARY TABLE t AS SELECT * FROM survey_run_units WHERE run_id = @src_run;
UPDATE t SET id = 0, run_id = @new_run;
UPDATE t SET unit_id = @new_form WHERE unit_id = @old_form;
UPDATE t SET unit_id = @new_page WHERE unit_id = @old_page;
INSERT INTO survey_run_units SELECT * FROM t;
DROP TEMPORARY TABLE t;

SELECT @new_run AS new_run_id, @new_study AS new_study_id, 'e2e-text-solo-v2' AS run_name,
       (SELECT COUNT(*) FROM survey_items WHERE study_id = @new_study) AS items;
