-- form_v2: per-study wording for the page navigation buttons (#322).
--
-- NULL (the default) keeps the built-in, language-dependent wording from
-- FormStrings ('Next' / 'Submit', 'Weiter' / 'Absenden', ...). A value
-- replaces it for this study:
--   next_button_label   — every page but the last ('nav.next'; in the solo
--                         layout also the page-boundary 'Continue')
--   submit_button_label — the last page ('nav.submit')
-- A `submit` item in the sheet still labels its own page's button.
ALTER TABLE `survey_studies`
    ADD COLUMN IF NOT EXISTS `next_button_label` VARCHAR(100) NULL DEFAULT NULL
        COMMENT 'form_v2: custom Next button text (NULL = built-in)',
    ADD COLUMN IF NOT EXISTS `submit_button_label` VARCHAR(100) NULL DEFAULT NULL
        COMMENT 'form_v2: custom Submit button text on the last page (NULL = built-in)';
