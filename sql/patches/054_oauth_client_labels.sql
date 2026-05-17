-- Multiple API credentials per user, distinguished by label.
--
-- Until now, OAuthHelper enforced one oauth_clients row per formr user
-- (createClient() refused to insert a second one). With per-client
-- scoping and per-client run allowlists landed in patch 052, the
-- one-credential-per-user limit pushes every API integration to share
-- the same scope+run set: a user who wants a narrow "session:read for
-- run 47" credential for an external dashboard cannot ALSO have a
-- "run:write" credential for a different automation without rotating
-- the first one out of existence.
--
-- This migration adds a `label` column to oauth_clients to make
-- multiple credentials addressable, and enforces uniqueness of
-- (user_id, label) so the admin UI can list them stably.
--
-- The label `internal` is reserved for the auto-managed credential
-- that createAccessTokenForUser mints on demand for short-lived
-- OpenCPU tokens (Functions.php:opencpu_prepare_api_access). It is
-- not surfaced in the user-facing list; OAuthHelper rejects user
-- attempts to create a credential with that label.
--
-- Backfill: any existing row gets label='default'. Users who already
-- have a credential will continue using it under that name; rotating
-- it from the UI is a no-op rename.

ALTER TABLE `oauth_clients`
  ADD COLUMN `label` VARCHAR(64) COLLATE utf8mb4_unicode_ci NULL
  AFTER `user_id`;

UPDATE `oauth_clients` SET `label` = 'default' WHERE `label` IS NULL;

ALTER TABLE `oauth_clients`
  MODIFY `label` VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL;

ALTER TABLE `oauth_clients`
  ADD UNIQUE KEY `uniq_oauth_clients_user_label` (`user_id`, `label`);
