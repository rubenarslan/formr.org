ALTER TABLE `survey_runs` ADD COLUMN IF NOT EXISTS `privacy` mediumtext COLLATE utf8mb4_unicode_ci;
ALTER TABLE `survey_runs` ADD COLUMN IF NOT EXISTS `privacy_parsed` mediumtext COLLATE utf8mb4_unicode_ci;
ALTER TABLE `survey_runs` ADD COLUMN IF NOT EXISTS `tos` mediumtext COLLATE utf8mb4_unicode_ci;
ALTER TABLE `survey_runs` ADD COLUMN IF NOT EXISTS `tos_parsed` mediumtext COLLATE utf8mb4_unicode_ci;
