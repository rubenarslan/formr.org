-- SQL Patch 120: survey_unit_sessions.form_version and form_doc.
--
-- A form_v2 form can be open in more than one document at once: two tabs,
-- the installed app and a browser tab. Every write a document makes (a page
-- submit, a page from its offline queue, an autosave) acts on its own view
-- of the form, and a page submit records which items that view's showifs
-- hide — clearing their answers. A document that has not seen a write made
-- in another one would hide, and clear, answers given there.
--
-- form_version counts the unit session's form writes and form_doc names the
-- document (main.js formDocId) that made the latest one. A document's state
-- block carries the version it was rendered at; a write is current while
-- nothing has been written since, or while the latest write was its own
-- (FormSubmissionService::claimWrite).
--
-- Drift-tolerant: ADD COLUMN IF NOT EXISTS. Appended, so the ALTER is instant.

ALTER TABLE `survey_unit_sessions`
  ADD COLUMN IF NOT EXISTS `form_version` INT(10) UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'form_v2 writes stored (FormSubmissionService::claimWrite)',
  ADD COLUMN IF NOT EXISTS `form_doc` VARCHAR(64) DEFAULT NULL
    COMMENT 'form_v2 document that made the latest write';
