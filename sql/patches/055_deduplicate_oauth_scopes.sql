-- Deduplicate oauth_scopes and prevent future duplicates.
--
-- The oauth_scopes table was created without a UNIQUE constraint on
-- `scope` (patch 009), so running the seed INSERT (patch 049) twice
-- e.g. during a fresh install that also seeds from schema.sql would
-- insert every scope row twice. This breaks OAuthHelper::validateScopes()
-- which counts the rows returned by SELECT scope WHERE scope IN (...)
-- and rejects any result where the row count doesn't match the number
-- of distinct scopes requested.
--
-- Rebuild the table without duplicates and add the unique constraint.

DROP TABLE IF EXISTS `oauth_scopes_tmp`;

CREATE TABLE `oauth_scopes_tmp` (
  `scope` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_default` tinyint(1) DEFAULT NULL,
  UNIQUE KEY `scope` (`scope`(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `oauth_scopes_tmp` (`scope`, `is_default`)
SELECT DISTINCT `scope`, `is_default` FROM `oauth_scopes`;

DROP TABLE `oauth_scopes`;

RENAME TABLE `oauth_scopes_tmp` TO `oauth_scopes`;
