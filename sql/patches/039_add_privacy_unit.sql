ALTER TABLE `survey_runs`
    ADD COLUMN `privacy` mediumtext COLLATE utf8mb4_unicode_ci;
ALTER TABLE `survey_runs`
    ADD COLUMN `privacy_parsed` mediumtext COLLATE utf8mb4_unicode_ci;
ALTER TABLE `survey_runs`
    ADD COLUMN `tos` mediumtext COLLATE utf8mb4_unicode_ci;
ALTER TABLE `survey_runs`
    ADD COLUMN `tos_parsed` mediumtext COLLATE utf8mb4_unicode_ci;
