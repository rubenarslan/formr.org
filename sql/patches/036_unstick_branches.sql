UPDATE `survey_unit_sessions`
INNER JOIN `survey_branches` ON `survey_branches`.`id` = `survey_unit_sessions`.`unit_id`
SET `survey_unit_sessions`.`queued` = 1
WHERE `survey_branches`.`automatically_jump` = 1 AND `survey_branches`.`automatically_go_on` = 1 AND `survey_unit_sessions`.`ended` IS NULL AND `survey_unit_sessions`.`created` > '2021-08-01';

DROP TABLE IF EXISTS `survey_cron_log`;