-- form_v2: DB-backed fixed-window rate limiting (FixedWindowRateLimiter).
--
-- The previous limiter kept its bucket in the participant's PHP session
-- ($_SESSION), which reset whenever the session cookie rotated (re-auth via
-- ?code=, private-mode re-open) — an indefinite bypass. Buckets are now keyed
-- server-side (typically "scope:run_session_id" or "scope:ip") in one shared
-- row per bucket, updated atomically via INSERT ... ON DUPLICATE KEY UPDATE so
-- concurrent PHP workers never lose counts to read-modify-write races.
--
-- Rows are tiny and self-expiring in practice: the limiter opportunistically
-- deletes rows whose window ended more than a day ago.
CREATE TABLE IF NOT EXISTS `formr_rate_limits` (
    `bucket_key` VARCHAR(80) NOT NULL,
    `window_start` INT UNSIGNED NOT NULL,
    `count` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`bucket_key`),
    KEY `idx_window_start` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
