CREATE TABLE `survey_sessions_queue` (
  `unit_session_id` bigint(20) unsigned NOT NULL,
  `run_session_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `expires` int(10) unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

ALTER TABLE `survey_sessions_queue`
  ADD PRIMARY KEY (`unit_session_id`), 
  ADD KEY `run_session_id` (`run_session_id`,`unit_id`);

# ALTER TABLE `survey_unit_sessions` ADD `queueable` TINYINT UNSIGNED NOT NULL DEFAULT '1' , ADD INDEX (`queueable`) ;
