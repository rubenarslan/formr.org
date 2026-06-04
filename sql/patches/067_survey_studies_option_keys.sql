-- SQL Patch 067: form_v2 solo-layout letter keys (A·B·C…) toggle.
--
-- `option_keys` controls whether the "solo" layout draws letter-key badges on
-- mc / mc_multiple option cards (and enables A/B/C keyboard selection). On by
-- default to match the shipped solo skin; admins can disable it per study. Only
-- read by the v2 renderer in solo layout; v1 + the default layout ignore it.

ALTER TABLE `survey_studies`
ADD COLUMN `option_keys` TINYINT(1) NOT NULL DEFAULT 1 AFTER `layout`;
