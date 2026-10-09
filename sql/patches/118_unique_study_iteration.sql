-- One study_iteration per unit session of a study. survey_unit_sessions.
-- unit_id is the study's id for every Survey and Form placement (patch
-- 116), so (unit_id, study_iteration) names a session's number within its
-- study. Patch 108 added the number with an index only, and a counter that
-- fell behind a number held by a session without a wide row handed it out
-- again (UnitSession::allocateStudyIteration now allocates above every
-- number a session holds).
--
-- Duplicates are renumbered before the key is added: within a study and
-- number the earliest session (lowest id) keeps it, and every later one
-- gets the next number above both the study's highest and its counter,
-- which is then raised past them. A migration cannot look into each
-- study's own results table, so where a later session is the one whose
-- wide row carries the number, its wide row keeps the old one.
--
-- Drift-tolerant: with no duplicates left the UPDATEs change nothing, and
-- the key is added IF NOT EXISTS.

UPDATE `survey_unit_sessions` `us`
  JOIN (
    SELECT `d`.`id`,
           GREATEST(`m`.`max_iteration`, COALESCE(`s`.`last_iteration`, 0))
             + ROW_NUMBER() OVER (PARTITION BY `d`.`unit_id` ORDER BY `d`.`id`) AS `new_iteration`
      FROM (
        SELECT `id`, `unit_id`,
               ROW_NUMBER() OVER (PARTITION BY `unit_id`, `study_iteration` ORDER BY `id`) AS `nth`
          FROM `survey_unit_sessions`
         WHERE `study_iteration` IS NOT NULL
      ) `d`
      JOIN (
        SELECT `unit_id`, MAX(`study_iteration`) AS `max_iteration`
          FROM `survey_unit_sessions`
         WHERE `study_iteration` IS NOT NULL
         GROUP BY `unit_id`
      ) `m` ON `m`.`unit_id` = `d`.`unit_id`
      LEFT JOIN `survey_studies` `s` ON `s`.`id` = `d`.`unit_id`
     WHERE `d`.`nth` > 1
  ) `r` ON `r`.`id` = `us`.`id`
   SET `us`.`study_iteration` = `r`.`new_iteration`;

UPDATE `survey_studies` `s`
  JOIN (
    SELECT `unit_id`, MAX(`study_iteration`) AS `max_iteration`
      FROM `survey_unit_sessions`
     WHERE `study_iteration` IS NOT NULL
     GROUP BY `unit_id`
  ) `m` ON `m`.`unit_id` = `s`.`id`
   SET `s`.`last_iteration` = `m`.`max_iteration`
 WHERE `s`.`last_iteration` < `m`.`max_iteration`;

ALTER TABLE `survey_unit_sessions`
  ADD UNIQUE KEY IF NOT EXISTS `uq_study_iteration` (`unit_id`, `study_iteration`);
