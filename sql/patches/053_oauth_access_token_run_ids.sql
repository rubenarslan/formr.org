-- Per-token run allowlist for OAuth access tokens.
--
-- Companion to patch 052 (oauth_client_runs) but operating at the
-- token level rather than the client level. Internal callers like
-- OpenCPU mint a short-lived token in the context of a specific run
-- (Functions.php::opencpu_prepare_api_access — the token is embedded
-- into one R variable for the lifetime of one OpenCPU call). Without
-- this column, those tokens would inherit the per-client allowlist
-- semantics: either unrestricted (if the user has no oauth_client_runs
-- rows) or restricted to whatever runs are in oauth_client_runs — both
-- wrong shapes for a single-purpose token that should only be able to
-- touch the one run it was minted for.
--
-- Resolution precedence in ApiBase::allowedRunIds():
--   1. token.run_ids NOT NULL   -> use it (per-call narrowing wins)
--   2. oauth_client_runs has rows -> use them (per-credential allowlist)
--   3. neither                   -> unrestricted
--
-- VARCHAR(2000) matches oauth_clients.scope width (patch 052). A
-- typical OpenCPU token will hold a single id; bulk operations could
-- in theory list many, but 2000 chars holds ~250 comma-delimited ids
-- which is far more than any realistic single-call use.

ALTER TABLE `oauth_access_tokens`
  ADD COLUMN `run_ids` VARCHAR(2000) COLLATE utf8mb4_unicode_ci NULL
  AFTER `scope`;
