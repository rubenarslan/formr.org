CREATE TABLE IF NOT EXISTS `survey_sessions_queue` (
  `unit_session_id` bigint(20) unsigned NOT NULL,
  `run_session_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `created` int(10) unsigned NOT NULL,
  `expires` int(10) unsigned NOT NULL,
  `run` varchar(45) COLLATE utf8_unicode_ci NOT NULL,
  `counter` int(10) unsigned NOT NULL DEFAULT '0',
  `execute` tinyint(1) unsigned NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CALL formr_add_primary_key_if_not_exists('survey_sessions_queue', '`unit_session_id`');
CREATE INDEX IF NOT EXISTS `run_session_id` ON `survey_sessions_queue` (`run_session_id`, `unit_id`);
CREATE INDEX IF NOT EXISTS `expires` ON `survey_sessions_queue` (`expires`);
