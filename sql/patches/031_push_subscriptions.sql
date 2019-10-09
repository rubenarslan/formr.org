CREATE TABLE `survey_push_subscriptions` (
      `id` INT NOT NULL
      `run_id` INT NOT NULL,
      `endpoint` LONGTEXT NOT NULL,
      `auth` VARCHAR(45) NOT NULL,
      `p256dh` MEDIUMTEXT NULL,
      `session` CHAR(64) NOT NULL,
      PRIMARY KEY (`id`));
