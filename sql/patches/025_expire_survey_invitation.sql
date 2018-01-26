ALTER TABLE `survey_studies`  ADD `expire_invitation_after` INT UNSIGNED NULL  AFTER `expire_after`,  ADD `expire_invitation_grace` INT UNSIGNED NULL  AFTER `expire_invitation_after`;
UPDATE `survey_studies` SET `expire_invitation_after` = `expire_after`;
