-- SQL Patch 059: survey_r_calls allowlist table
--
-- form_v2 Phase 3: dedicated store for r(...)-wrapped R expressions that must
-- be evaluated on the server (showif, value, label/page_body/choice_label in
-- later phases). Each study's allowlist is populated at spreadsheet import and
-- referenced by id from the participant-facing HTML — the raw R never ships to
-- the client, and the server won't evaluate anything that isn't already in the
-- allowlist. (expr_hash lets us dedup identical expressions across items.)

CREATE TABLE IF NOT EXISTS `survey_r_calls` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `study_id` INT(10) UNSIGNED NOT NULL,
    `slot` ENUM('showif','value','label','page_body','choice_label') NOT NULL,
    `item_id` INT(10) UNSIGNED NULL DEFAULT NULL,
    `expr` TEXT NOT NULL,
    `expr_hash` CHAR(64) NOT NULL,
    `created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `modified` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `study_slot` (`study_id`, `slot`),
    KEY `item_id` (`item_id`),
    -- item_id is part of the key: /form-render-page resolves a page's calls
    -- by joining survey_items_display on r.item_id, so two items sharing an
    -- identical expression need one row EACH — deduping across items left the
    -- non-last-written item's deferred value/label unresolvable on its page.
    UNIQUE KEY `study_expr_hash_slot` (`study_id`, `expr_hash`, `slot`, `item_id`),
    CONSTRAINT `fk_survey_r_calls_study` FOREIGN KEY (`study_id`)
        REFERENCES `survey_studies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
