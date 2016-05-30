ALTER TABLE `survey_externals` ADD   `expire_after` int(10) unsigned DEFAULT NULL;
ALTER TABLE `survey_unit_sessions` ADD  `expired` datetime DEFAULT NULL;
