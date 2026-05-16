-- API scoping: per-run allowlist + read/write verb scopes.
--
-- 1. New oauth_client_runs(client_id, run_id) — empty rows for a client
--    means "no run restriction" (back-compat with internal callers like
--    OpenCPU that mint tokens via createAccessTokenForUser without
--    touching this table).
-- 2. Widen oauth_clients.scope from VARCHAR(100) to VARCHAR(2000) so the
--    chosen scope list never silently truncates (11 default verbs already
--    serialise to ~120 chars).
-- 3. Drop default-grant from oauth_scopes; users must now pick scopes
--    explicitly when issuing credentials.
-- 4. Force re-issue: invalidate every outstanding access/refresh/auth
--    token so no token grants the old all-11-scopes blanket access after
--    this migration applies. Clients keep their client_id + hashed
--    secret and can immediately mint a new token via the admin UI.

CREATE TABLE `oauth_client_runs` (
  `client_id` VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `run_id`    INT(10) UNSIGNED NOT NULL,
  PRIMARY KEY (`client_id`, `run_id`),
  KEY `idx_ocr_run_id` (`run_id`),
  CONSTRAINT `fk_ocr_client` FOREIGN KEY (`client_id`)
    REFERENCES `oauth_clients` (`client_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ocr_run` FOREIGN KEY (`run_id`)
    REFERENCES `survey_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `oauth_clients` MODIFY `scope` VARCHAR(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL;

UPDATE `oauth_scopes` SET `is_default` = 0;

DELETE FROM `oauth_access_tokens`;
DELETE FROM `oauth_refresh_tokens`;
DELETE FROM `oauth_authorization_codes`;
