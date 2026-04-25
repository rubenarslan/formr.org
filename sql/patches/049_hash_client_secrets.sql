-- Store SHA-256 hashes of OAuth client_secret values at rest, matching the
-- treatment of access / refresh / authorization codes in patch 048. Existing
-- plaintext secrets will never match the hashed lookups HashedTokenOAuth2StoragePdo
-- now performs in checkClientCredentials, so purge them. Users generate a
-- fresh secret from /admin/account (shown once, hash stored).
UPDATE `oauth_clients` SET `client_secret` = '';
