-- form_v2 Phase 5: offline-queue dedup ledger.
-- Client generates a UUID per queued page submission; server records the UUID
-- on first apply so retries (from sync-on-reconnect or Background Sync) are
-- idempotent. FK CASCADE keeps the ledger coupled to unit session lifetime.
CREATE TABLE IF NOT EXISTS `survey_form_submissions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `unit_session_id` INT(10) UNSIGNED NOT NULL,
    `page` SMALLINT UNSIGNED NOT NULL,
    `client_ts` DATETIME NULL DEFAULT NULL,
    `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uuid` (`uuid`),
    KEY `unit_session_id` (`unit_session_id`),
    CONSTRAINT `fk_sfs_unit_session` FOREIGN KEY (`unit_session_id`)
        REFERENCES `survey_unit_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
