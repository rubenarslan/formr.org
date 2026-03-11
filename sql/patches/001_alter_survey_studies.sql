-- Add unique index and drop old index (idempotent)
CALL formr_drop_index_if_exists('survey_studies', 'name');
CREATE UNIQUE INDEX IF NOT EXISTS `name_by_user` ON `survey_studies` (`user_id`, `name`);
UPDATE `survey_studies` SET `results_table` = `name` WHERE `results_table` IS NULL OR `results_table` = '';
