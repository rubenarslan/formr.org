CREATE TABLE IF NOT EXISTS `survey_privacy`
(
    `id`                   int(10) unsigned NOT NULL,
    `privacy_label`        mediumtext COLLATE utf8mb4_unicode_ci,
    `privacy_label_parsed` mediumtext COLLATE utf8mb4_unicode_ci,
    `tos_label`            mediumtext COLLATE utf8mb4_unicode_ci,
    `tos_label_parsed`     mediumtext COLLATE utf8mb4_unicode_ci,
    PRIMARY KEY (`id`),
    KEY `fk_survey_privacy_survey_units1_idx` (`id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

ALTER TABLE `survey_privacy`
    ADD CONSTRAINT `fk_privacy_unit` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION;

ALTER TABLE `survey_runs`
    ADD COLUMN `privacy` mediumtext COLLATE utf8mb4_unicode_ci;
ALTER TABLE `survey_runs`
    ADD COLUMN `privacy_parsed` mediumtext COLLATE utf8mb4_unicode_ci;
ALTER TABLE `survey_runs`
    ADD COLUMN `tos` mediumtext COLLATE utf8mb4_unicode_ci;
ALTER TABLE `survey_runs`
    ADD COLUMN `tos_parsed` mediumtext COLLATE utf8mb4_unicode_ci;
ALTER TABLE `survey_runs`
    ADD COLUMN `imprint` mediumtext COLLATE utf8mb4_unicode_ci;
ALTER TABLE `survey_runs`
    ADD COLUMN `imprint_parsed` mediumtext COLLATE utf8mb4_unicode_ci;
