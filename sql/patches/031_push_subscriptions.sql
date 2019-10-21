CREATE TABLE `survey_push_subscriptions` (
      `run_id` int(11) NOT NULL,
      `endpoint` varchar(500) NOT NULL,
      `auth` varchar(45) NOT NULL,
      `p256dh` mediumtext,
      `session` char(64) NOT NULL,
      `id` int(11) NOT NULL AUTO_INCREMENT,
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_index` (`run_id`,`endpoint`,`session`)
      ) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8;

