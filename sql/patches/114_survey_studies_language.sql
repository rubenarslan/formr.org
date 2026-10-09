-- form_v2 i18n plumbing: per-study participant-facing language.
--
-- BCP 47 language tag (max 35 chars per RFC 5646), default 'en'. Drives:
--   - <html lang="..."> and dir="rtl" (computed from the primary subtag)
--     on the v2 participant page (templates/run/form_index.php)
--   - the FormStrings table injected as window.fmrStrings, which localizes
--     the v2 client bundle's UI strings (nav buttons, save pill, offline
--     banners, validation fallbacks, solo hints)
--
-- Item labels/choices are authored in the study's own language already;
-- this column covers the ENGINE's chrome around them, which was previously
-- hardcoded English.
ALTER TABLE `survey_studies`
    ADD COLUMN IF NOT EXISTS `language` VARCHAR(35) NOT NULL DEFAULT 'en'
    COMMENT 'BCP 47 tag for participant-facing engine chrome (form_v2 i18n)';
