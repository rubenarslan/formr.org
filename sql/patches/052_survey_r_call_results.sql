-- SQL Patch 052: r-call result cache (form_v2 Phase 4 polish).
--
-- Memoizes OpenCPU-evaluated results for allowlisted r(...) expressions,
-- keyed on (call_id, args_hash). The reactive showif cadence is already
-- one-per-keystroke so repeated identical lookups are common in practice —
-- this table lets /form-r-call and /form-fill short-circuit those.
--
-- Eviction: rows older than TTL (default 30s for showif / 5 minutes for
-- value) are deleted at read time and on a cron pass (future).

CREATE TABLE IF NOT EXISTS `survey_r_call_results` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `call_id` BIGINT UNSIGNED NOT NULL,
    `args_hash` CHAR(64) NOT NULL COMMENT 'sha256 of normalized JSON-encoded args',
    `result_json` TEXT NOT NULL COMMENT 'JSON-encoded {result: ...} payload',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_call_args` (`call_id`, `args_hash`),
    KEY `idx_created` (`created_at`),
    CONSTRAINT `fk_r_call_results_call` FOREIGN KEY (`call_id`)
        REFERENCES `survey_r_calls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
