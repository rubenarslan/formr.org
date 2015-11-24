CREATE TABLE IF NOT EXISTS `osf` (
  `user_id` int(10) unsigned NOT NULL,
  `access_token` varchar(150) NOT NULL,
  `access_token_expires` int(10) unsigned NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;
