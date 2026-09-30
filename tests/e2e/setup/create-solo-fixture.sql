-- Create the `all_widgets_solo` e2e fixture: a solo-layout twin of the existing
-- e2e-aw-v2 run (all_widgets v2). One-time, host-side setup — NOT run by tests
-- (tests must not mutate the DB; that breaks under the BrowserStack SDK and is
-- the whole reason this fixture exists). Re-runnable guard lives in the wrapper
-- script (create-solo-fixture.sh): it skips if e2e-aw-solo-v2 already exists.
--
-- Mechanism: temp-table `SELECT *` then `id = 0` so MySQL reassigns a fresh
-- auto_increment id on INSERT. survey_item_choices link by (study_id, list_name)
-- — strings, not item ids — so no per-item id remapping is needed.

SET @src_run    := (SELECT id FROM survey_runs WHERE name = 'e2e-aw-v2' LIMIT 1);
SET @old_form   := (SELECT u.id FROM survey_units u JOIN survey_run_units ru ON ru.unit_id = u.id WHERE ru.run_id = @src_run AND u.type = 'Form' LIMIT 1);
SET @old_page   := (SELECT u.id FROM survey_units u JOIN survey_run_units ru ON ru.unit_id = u.id WHERE ru.run_id = @src_run AND u.type = 'Page' LIMIT 1);
SET @src_study  := @old_form; -- a Form unit's id is its study's id

-- 1a. study-anchor unit FIRST: survey_studies.id is FK'd to
-- survey_units.id (legacy shared-primary-key quirk), so the study's id must be
-- an existing survey_units row. Clone the anchor unit, then give the study its id.
CREATE TEMPORARY TABLE t AS SELECT * FROM survey_units WHERE id = @src_study;
UPDATE t SET id = 0;
INSERT INTO survey_units SELECT * FROM t;
SET @new_study := LAST_INSERT_ID();
DROP TEMPORARY TABLE t;

-- 1b. study (id = its anchor unit, renamed, solo layout, v2)
CREATE TEMPORARY TABLE t AS SELECT * FROM survey_studies WHERE id = @src_study;
UPDATE t SET id = @new_study, name = 'e2e_all_widgets_solo_v2', layout = 'solo', rendering_mode = 'v2';
INSERT INTO survey_studies SELECT * FROM t;
DROP TEMPORARY TABLE t;

-- 1c. DEDICATED results table. survey_studies.results_table was copied verbatim
-- in 1b, so the clone would otherwise SHARE the source study's results table —
-- and createSurveyStudyRecord writes the per-study `study_iteration` into that
-- table's UNIQUE `iteration` column, so two studies sharing it collide the moment
-- their counters overlap ("Duplicate entry for key 'iteration'", blank form).
-- Give the clone its own table and reset its counter.
SET @src_rt := (SELECT results_table FROM survey_studies WHERE id = @src_study);
SET @new_rt := CONCAT('s', @new_study, '_all_widgets_solo');
SET @ddl    := CONCAT('CREATE TABLE `', @new_rt, '` LIKE `', @src_rt, '`');
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;
UPDATE survey_studies SET results_table = @new_rt, last_iteration = 0 WHERE id = @new_study;

-- 2. items
CREATE TEMPORARY TABLE t AS SELECT * FROM survey_items WHERE study_id = @src_study;
UPDATE t SET id = 0, study_id = @new_study;
INSERT INTO survey_items SELECT * FROM t;
DROP TEMPORARY TABLE t;

-- 3. item choices (link by study_id + list_name)
CREATE TEMPORARY TABLE t AS SELECT * FROM survey_item_choices WHERE study_id = @src_study;
UPDATE t SET id = 0, study_id = @new_study;
INSERT INTO survey_item_choices SELECT * FROM t;
DROP TEMPORARY TABLE t;

-- 4. Form unit: a Form unit IS its study (the anchor row cloned in 1a)
SET @new_form := @new_study;

-- 5. trailing Page unit (verbatim)
CREATE TEMPORARY TABLE t AS SELECT * FROM survey_units WHERE id = @old_page;
UPDATE t SET id = 0;
INSERT INTO survey_units SELECT * FROM t;
SET @new_page := LAST_INSERT_ID();
DROP TEMPORARY TABLE t;

-- 6. run
CREATE TEMPORARY TABLE t AS SELECT * FROM survey_runs WHERE id = @src_run;
UPDATE t SET id = 0, name = 'e2e-aw-solo-v2';
INSERT INTO survey_runs SELECT * FROM t;
SET @new_run := LAST_INSERT_ID();
DROP TEMPORARY TABLE t;

-- 7. run units (remap unit ids to the cloned units)
CREATE TEMPORARY TABLE t AS SELECT * FROM survey_run_units WHERE run_id = @src_run;
UPDATE t SET id = 0, run_id = @new_run;
UPDATE t SET unit_id = @new_form WHERE unit_id = @old_form;
UPDATE t SET unit_id = @new_page WHERE unit_id = @old_page;
INSERT INTO survey_run_units SELECT * FROM t;
DROP TEMPORARY TABLE t;

-- (Special units like the ServiceMessagePage are intentionally NOT cloned:
-- survey_run_special_units.id is itself FK'd to survey_units.id, and the solo
-- fixture doesn't need them — only the Form body + trailing Page matter.)

SELECT @new_run AS new_run_id, @new_study AS new_study_id, 'e2e-aw-solo-v2' AS run_name;
