-- Add column for run-specific R functions file path, analogous to
-- custom_css_path / custom_js_path. R functions defined here are
-- injected into every OpenCPU evaluation and knitr rendering call
-- for this run, making them available in showif, value, feedback,
-- relative_to, branch conditions, external URLs, email body, etc.

ALTER TABLE `survey_runs`
    ADD COLUMN `custom_r_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `custom_js_path`;
