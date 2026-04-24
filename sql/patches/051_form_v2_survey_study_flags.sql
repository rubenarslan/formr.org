-- SQL Patch 051: form_v2 per-study behaviour flags
--
-- `offline_mode` (default 1 / opt-out): when 0, the v2 client skips the
-- IndexedDB queue and surfaces fetch failures immediately. Admins of studies
-- collecting sensitive data can turn off local persistence so answers never
-- hit IDB.
--
-- `allow_previous` (default 0 / opt-in): when 1, v2 renders a "Previous"
-- button on non-first pages so participants can navigate backwards within
-- already-rendered pages. Off by default because back-navigation invites
-- admins to author pages that depend on the participant seeing everything
-- in order once.
--
-- Both columns are v2-only in effect; v1 surveys ignore them.

ALTER TABLE `survey_studies`
ADD COLUMN `offline_mode` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 AFTER `rendering_mode`,
ADD COLUMN `allow_previous` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `offline_mode`;
