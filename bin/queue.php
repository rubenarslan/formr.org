#!/usr/bin/php
<?php

require_once dirname(__FILE__) . '/../setup.php';

/**
 * Usage:
 * - php queue.php
 * - php queue.php -a 123 -t Email
 * - php queue.php -t UnitSession -o push
 * 
 * Parameters:
 * - t : The type if the queue to process. Values: 'Email', 'UnitSession'
 * - a : In the case of Email queue, the integer ID of the email account whose emails should be processed.
 * - o : In the case of UnitSession queue, this indicates which operation to perform on queue: Either to add to queue ('push') or remove from queue ('pop')
 */

// Check if maintenance is going on
if (Config::get('in_maintenance')) {
	formr_error(404, 'Not Found', 'This website is currently undergoing maintenance. Please try again later.', 'Maintenace Mode', false);
}

$opts = getopt('t:a:o:');

$queueType = 'Email';
if (!empty($opts['t'])) {
	$queueType = $opts['t'];
}

$account_id = null;
if (!empty($opts['a'])) {
	$account_id = (int)$opts['a'];
}

$config = array();
if (!empty($opts['o'])) {
	$config['queue_operation'] = $opts['o'];
}

try {
	if ($queueType === 'Email') {
		$queue = new EmailQueue(DB::getInstance(), Config::get('email'));
		$queue->run($account_id);
	} elseif ($queueType === 'UnitSession') {
		$queue = new UnitSessionQueue(DB::getInstance(), Config::get('unit_session', array()));
		$queue->run($config);
	}
} catch (Exception $e) {
	formr_log_exception($e, 'Mailer');
	sleep(15);
}
