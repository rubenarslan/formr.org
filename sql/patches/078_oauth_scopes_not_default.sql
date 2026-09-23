-- 078: stop oauth_scopes from being granted by default.
--
-- Why: patch 049 seeded all 11 scopes with is_default = 1. bshaffer's
-- token endpoint hands the default scope set to any client_credentials
-- request that omits a scope parameter, so a credential created with NO
-- scopes (OAuthHelper stored scope = '') minted a token carrying ALL 11
-- scopes — the opposite of the documented "empty = deny all" contract.
-- With no default scopes, such a request gets an empty scope and every
-- ApiBase::checkScope() denies. User credentials must now also declare at
-- least one scope (OAuthHelper::createClient), so their stored scope is
-- authoritative; the auto-managed internal client keeps empty scope but
-- never uses this default path (its tokens are scoped at issuance).
--
-- Idempotent / drift-tolerant: re-running only re-asserts is_default = 0.

UPDATE `oauth_scopes` SET `is_default` = 0 WHERE `is_default` <> 0;
