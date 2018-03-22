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

// Check if maintenance is going on
if (Config::get('in_maintenance')) {
	formr_error(404, 'Not Found', 'This website is currently undergoing maintenance. Please try again later.', 'Maintenace Mode', false);
}

$account_id = null;
$opts = getopt('a:');
if (!empty($opts['a'])) {
	$account_id = (int)$opts['a'];
}

$queue = new EmailQueue(DB::getInstance());
$queue->run($account_id);
