-- SQL Patch 067: form_v2 solo-layout letter keys (A·B·C…) toggle.
--
-- DORMANT COLUMN. `option_keys` was added for the solo layout's letter-key
-- badges (A/B/C on mc option cards + keyboard selection); the feature was
-- subsequently removed end-to-end (commit 5c6c50be: model property, toArray,
-- admin UI, renderer, CSS all gone). The column ships unread — it is the one
-- deliberate exception to the three-touch-point rule (patch + public property
-- + toArray). Kept because hosts tracking the branch already applied this
-- patch; reuse it (and re-add the model wiring) if the feature returns.

ALTER TABLE `survey_studies`
ADD COLUMN `option_keys` TINYINT(1) NOT NULL DEFAULT 1 AFTER `layout`;
