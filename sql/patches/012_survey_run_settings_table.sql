
CREATE TABLE IF NOT EXISTS `survey_run_settings` (
  `run_session_id` int(10) unsigned NOT NULL,
  `settings` text NOT NULL,
  PRIMARY KEY (`run_session_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;
