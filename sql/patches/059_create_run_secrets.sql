CREATE TABLE `survey_run_secrets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int(10) unsigned NOT NULL,
  `name` varchar(255) NOT NULL COMMENT 'Variable name without secret_ prefix',
  `value_encrypted` text NOT NULL COMMENT 'Encrypted via Crypto::encrypt()',
  `created` datetime NOT NULL,
  `modified` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `run_secret_name` (`run_id`, `name`),
  KEY `run_id` (`run_id`),
  CONSTRAINT `survey_run_secrets_ibfk_1` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
