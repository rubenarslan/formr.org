ALTER TABLE `survey_studies` 
ADD UNIQUE INDEX `name_by_user` (`user_id` ASC, `name` ASC),
DROP INDEX `name` ;
UPDATE `survey_studies` SET `results_table` = `name`;
