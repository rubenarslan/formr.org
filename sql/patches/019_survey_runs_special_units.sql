CREATE TABLE `survey_run_special_units` (
  `id` int(10) unsigned NOT NULL,
  `run_id` int(10) unsigned NOT NULL,
  `type` varchar(25) NOT NULL,
  `description` varchar(225) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `run_id` (`run_id`),
  KEY `type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


ALTER TABLE `survey_run_special_units`
  ADD CONSTRAINT `survey_run_special_units_ibfk_1` FOREIGN KEY (`id`) REFERENCES `survey_units` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  ADD CONSTRAINT `survey_run_special_units_ibfk_2` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION;
