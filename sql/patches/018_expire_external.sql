ALTER TABLE `survey_externals` ADD COLUMN IF NOT EXISTS `expire_after` int(10) unsigned DEFAULT NULL;
ALTER TABLE `survey_unit_sessions` ADD COLUMN IF NOT EXISTS `expired` datetime DEFAULT NULL;
