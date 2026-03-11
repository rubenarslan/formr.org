ALTER TABLE `survey_studies` ADD COLUMN IF NOT EXISTS `expire_invitation_after` INT UNSIGNED NULL;
ALTER TABLE `survey_studies` ADD COLUMN IF NOT EXISTS `expire_invitation_grace` INT UNSIGNED NULL;
UPDATE `survey_studies` SET `expire_invitation_after` = `expire_after` WHERE `expire_invitation_after` IS NULL AND `expire_after` IS NOT NULL;
