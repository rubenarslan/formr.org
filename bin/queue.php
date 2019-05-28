#!/usr/bin/php
<?php
require_once dirname(__FILE__) . '/../setup.php';

/**
 * Usage:
 * - php queue.php
 * - php queue.php -a 123 -t Email
 * - php queue.php -t UnitSession
 * 
 * Parameters:
 * - t : The type if the queue to process. Values: 'Email', 'UnitSession'
 * - a : In the case of Email queue, the integer ID of the email account whose emails should be processed.
 * --batch-offset: For the unit session queue, one can process unit sessions from a particular offset (defaults to 0)
 * --batch-limit : For the unit session queue this is the number of items to fetch per SQL query (defaults to 1000)
 * --max-sessions: Maximum number of unit sessions the UnitSession queue is allowed to process
 */
// Check if maintenance is going on
if (Config::get('in_maintenance')) {
    formr_error(404, 'Not Found', 'This website is currently undergoing maintenance. Please try again later.', 'Maintenace Mode', false);
}
//@todo explain variables
$opts = getopt('t:a:o:p:n:b:');

$config = (array) $opts;
$config['queue_type'] = 'Email';
$config['account_id'] = null;

if (!empty($opts['t'])) {
    $config['queue_type'] = $opts['t'];
}

if (!empty($opts['a'])) {
    $config['account_id'] = (int) $opts['a'];
}

if (!empty($opts['p'])) {
    $config['process_number'] = (int) $opts['p'] + 1;
}

if (!empty($opts['p'])) {
    $config['total_processes'] = (int) $opts['n'];
}

if (!empty($opts['b'])) {
    $config['items_per_process'] = (int) $opts['b'];
}


try {
    $queue = null;

    if ($config['queue_type'] === 'Email') {
        $config = array_merge(Config::get('email'), $config);
        $queue = new EmailQueue(DB::getInstance(), $config);
    }

    if ($config['queue_type'] === 'UnitSession') {
        $config = array_merge(Config::get('unit_session'), $config);
        $queue = new UnitSessionQueue(DB::getInstance(), $config);
    }

    if ($queue === null) {
        throw new Exception('Invalid Queue Type: ' . $config['queue_type']);
    }

    $queue->run();
} catch (Exception $e) {
    formr_log_exception($e, 'Queue');
    sleep(15);
}
