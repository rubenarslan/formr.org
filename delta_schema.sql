ALTER TABLE `formr`.`survey_run_units` ADD INDEX `position_in_run` ( `run_id` , `position` ) ;
ALTER TABLE `formr`.`survey_unit_sessions` ADD INDEX `ended` ( `ended` ) ;
ALTER TABLE `formr`.`survey_units` ADD INDEX `type` ( `type` ) ;
ALTER TABLE `formr`.`survey_studies` ADD UNIQUE `name` ( `name` ) ;
ALTER TABLE `formr`.`survey_items_display` DROP INDEX `session_item_views` , 
ADD UNIQUE `session_item_views` ( `session_id` , `item_id` ) ;
ALTER TABLE `formr`.`survey_items_display` ADD INDEX `answered` ( `session_id` , `answered` ) ;
ALTER TABLE `formr`.`survey_run_sessions` ADD INDEX `position` ( `position` ) ;

