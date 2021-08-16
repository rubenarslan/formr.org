ALTER TABLE `survey_run_sessions` RENAME COLUMN `current_unit_id` TO `current_unit_session_id`;
ALTER TABLE `survey_run_sessions` DROP FOREIGN KEY `fk_survey_run_sessions_survey_units1`;

UPDATE `survey_run_sessions`
	LEFT JOIN `survey_unit_sessions` m ON m.run_session_id = `survey_run_sessions`.id
    LEFT JOIN `survey_unit_sessions` b             -- "b" from "bigger"
        ON m.run_session_id = b.run_session_id   -- match "max" row with "bigger" row by `home`
        AND m.id < b.id           			      -- want "bigger" than "max"
SET  `survey_run_sessions`.`current_unit_session_id` = m.id 
WHERE m.run_session_id IS NOT NULL AND b.id IS NULL;

DROP TABLE IF EXISTS `survey_sessions_queue`;

--	SELECT run_id, `session`, position, m.id, m.queued, m.expires, m.ended, m.expired FROM `survey_run_sessions`
--		LEFT JOIN `survey_unit_sessions` m ON m.run_session_id = `survey_run_sessions`.id
--	    LEFT JOIN `survey_unit_sessions` b             -- "b" from "bigger"
--	        ON m.run_session_id = b.run_session_id   -- match "max" row with "bigger" row by `home`
--	        AND m.id < b.id           			      -- want "bigger" than "max"
--	WHERE m.run_session_id IS NOT NULL AND b.id IS NULL;

--		SET GLOBAL join_buffer_size=524288;
--		SELECT run_id, `session`, position, m.id, m.queued, m.expires, m.ended, m.expired FROM `survey_run_sessions`
--			LEFT JOIN `survey_unit_sessions` m ON m.run_session_id = `survey_run_sessions`.id
--			LEFT JOIN `survey_unit_sessions` b             -- "b" from "bigger"
--				ON m.run_session_id = b.run_session_id   -- match "max" row with "bigger" row by `home`
--				AND m.id < b.id           			      -- want "bigger" than "max"
--		WHERE m.run_session_id IS NOT NULL AND b.id IS NULL LIMIT 20000,10000;
--		SET GLOBAL join_buffer_size=262144;