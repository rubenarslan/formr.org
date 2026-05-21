-- Store SHA-256 hashes of OAuth bearer credentials at rest. Existing
-- plaintext tokens cannot match the hashed lookups performed by the new
-- HashedTokenOAuth2StoragePdo, so they would only sit in the table as readable
-- secrets — purge them. Clients re-auth as needed.
TRUNCATE TABLE `oauth_access_tokens`;
TRUNCATE TABLE `oauth_refresh_tokens`;
TRUNCATE TABLE `oauth_authorization_codes`;

-- SHA-256 hex is 64 chars; widen the key columns to fit.
ALTER TABLE `oauth_access_tokens` MODIFY `access_token` VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '';
ALTER TABLE `oauth_refresh_tokens` MODIFY `refresh_token` VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '';
ALTER TABLE `oauth_authorization_codes` MODIFY `authorization_code` VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '';
