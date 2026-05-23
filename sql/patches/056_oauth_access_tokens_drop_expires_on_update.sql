-- Strip MariaDB's auto-applied `ON UPDATE current_timestamp()` clause
-- from `oauth_access_tokens.expires`.
--
-- The TIMESTAMP NOT NULL column was created without an explicit DEFAULT
-- or ON UPDATE, so MariaDB filled in both:
--
--   `expires` timestamp NOT NULL
--       DEFAULT current_timestamp()
--       ON UPDATE current_timestamp()
--
-- The ON UPDATE clause then bit OAuthHelper::createAccessTokenForUser,
-- which does a follow-up `UPDATE … SET run_ids = …` after bshaffer's
-- INSERT to stamp the per-token run allowlist. That UPDATE leaves the
-- expires column out of the SET list, so MariaDB silently rewrites
-- expires = NOW() — clobbering the lifetime the caller just set.
-- Every embedded OpenCPU token was therefore born already expired
-- (t > expires after t+1s) and the first API call from inside the R
-- render 401'd with `invalid_token, "The access token provided has
-- expired"`.
--
-- Keep DEFAULT current_timestamp() (harmless — application code always
-- provides expires on INSERT) and drop ON UPDATE.

ALTER TABLE `oauth_access_tokens`
    MODIFY `expires` timestamp NOT NULL DEFAULT current_timestamp();
