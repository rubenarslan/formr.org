CREATE TABLE IF NOT EXISTS `survey_run_special_units` (
  `id` int(10) unsigned NOT NULL,
  `run_id` int(10) unsigned NOT NULL,
  `type` varchar(25) NOT NULL,
  `description` varchar(225) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `run_id` (`run_id`),
  KEY `type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CALL formr_add_foreign_key_if_not_exists('survey_run_special_units', 'survey_run_special_units_ibfk_1', 'id', 'survey_units', 'id', 'CASCADE', 'NO ACTION');
CALL formr_add_foreign_key_if_not_exists('survey_run_special_units', 'survey_run_special_units_ibfk_2', 'run_id', 'survey_runs', 'id', 'CASCADE', 'NO ACTION');
