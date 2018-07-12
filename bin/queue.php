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
 * --batch-offset: For the unit session queue, one can process unit sessions from a particular offset (defaults to 0)
 * --batch-limit : For the unit session queue this is the number of items to fetch per SQL query (defaults to 1000)
 * --max-sessions: Maximum number of unit sessions the UnitSession queue is allowed to process
 */

// Check if maintenance is going on
if (Config::get('in_maintenance')) {
	formr_error(404, 'Not Found', 'This website is currently undergoing maintenance. Please try again later.', 'Maintenace Mode', false);
}

$opts = getopt('t:a:o:', array('batch-offset:', 'batch-limit:', 'max-sessions:'));
$config = (array)$opts;
$config['queue_type'] = 'Email';
$config['account_id'] = null;
$config['queue_operation'] = 'push';

if (!empty($opts['t'])) {
	$config['queue_type'] = $opts['t'];
}

if (!empty($opts['a'])) {
	$config['account_id'] = (int)$opts['a'];
}

if (!empty($opts['o'])) {
	$config['queue_operation'] = $opts['o'];
}

try {
	if ($config['queue_type'] === 'Email') {
		$queue = new EmailQueue(DB::getInstance(), Config::get('email'));
	} elseif ($config['queue_type'] === 'UnitSession') {
		$queue = new UnitSessionQueue(DB::getInstance(), Config::get('unit_session', array()));
	}

	$queue->run($config);
} catch (Exception $e) {
	formr_log_exception($e, 'Queue');
	sleep(15);
}
