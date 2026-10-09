-- 076: outbound-mail guard (signup-driven mail abuse hardening).
--
-- Why: registration, resend-verification and password-reset mail went out
-- through a bare PHPMailer with no log, no rate limit and no suppression
-- support, so a bot submitting the signup / forgot-password forms could use
-- the instance as a free mailer for subscription bombing (verified in an
-- incident where a provider disabled SMTP for the whole mailbox). These four
-- tables back the new central send-path guard (GuardedMailer +
-- MailGuardService):
--
--   survey_mail_guard_log      one row per attempted handoff to the mailer:
--                              who was mailed (or refused), which message
--                              type, from which source IP, with what outcome.
--                              `subnet` is the /24 (IPv4) or /64 (IPv6)
--                              bucket, precomputed so the per-subnet limit
--                              can use an index. No message bodies, ever.
--   survey_mail_suppressions   operator-maintained do-not-mail list
--                              (bin/suppression.php); checked before every
--                              send on every path.
--   survey_registration_invites single-use signup tokens for
--                              registration.mode = 'invite'
--                              (bin/registration_invite.php). Only a sha256
--                              of the token is stored.
--   survey_mail_domain_cache   cached MX/A plausibility verdict per recipient
--                              domain, so DNS is consulted at most once per
--                              domain per cache window.
--
-- IF NOT EXISTS guards: replayable against a schema.sql that already carries
-- the tables (fresh installs are baselined at the highest patch without
-- running them; see 073's header for the convention).

CREATE TABLE IF NOT EXISTS `survey_mail_guard_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `recipient` varchar(255) NOT NULL,
  `type` varchar(32) NOT NULL,
  `category` varchar(16) NOT NULL DEFAULT 'transactional',
  `user_id` int(10) unsigned DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `subnet` varchar(45) DEFAULT NULL,
  `outcome` varchar(32) NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mail_guard_recipient` (`recipient`,`type`,`created`),
  KEY `idx_mail_guard_ip` (`ip`,`created`),
  KEY `idx_mail_guard_subnet` (`subnet`,`created`),
  KEY `idx_mail_guard_outcome` (`category`,`outcome`,`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `survey_mail_suppressions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `address` varchar(255) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `added_by` varchar(255) DEFAULT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_mail_suppression_address` (`address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `survey_registration_invites` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `token_hash` varchar(64) NOT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `created` datetime NOT NULL,
  `expires` datetime DEFAULT NULL,
  `used_at` datetime DEFAULT NULL,
  `used_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_invite_token_hash` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `survey_mail_domain_cache` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) NOT NULL,
  `deliverable` tinyint(1) NOT NULL,
  `checked` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_mail_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
