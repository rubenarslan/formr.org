#!/usr/bin/php
<?php

require_once dirname(__FILE__) . '/../setup.php';

/**
 * Usage:
 * - php mailer.php
 * - php mailer.php -a 123
 * 
 * Parameters:
 * - a : The integer ID of the email account whose emails should be processed.
 */

$account_id = null;
$opts = getopt('a:');
if (!empty($opts['a'])) {
	$account_id = (int)$opts['a'];
}

$queue = new EmailQueue(DB::getInstance());
$queue->run($account_id);
