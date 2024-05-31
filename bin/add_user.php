#!/usr/bin/php
<?php
require_once dirname(__FILE__) . '/../setup.php';

$opts = getopt('e:p:l:');


if (empty($opts['e']) || empty($opts['p'])) {
    throw new Exception("Specify [e]mail and [p]assword");
}

$config = array();

$config['email'] = $opts['e'];
$config['password'] = $opts['p'];

if(empty($opts['l'])) {
	$config['level'] = 0;
} else {
	$config['level'] = $opts['l'];
}

$config['hash'] = password_hash($config['password'], PASSWORD_DEFAULT);

$db = DB::getInstance();
$users = $db->count('survey_users');

print("$users exist already");

if($config['level'] > 1 && $users > 0) {
	print("Cannot create superadmins when users already exist");
}

$inserted = $db->insert('survey_users', array(
	'email' => $config['email'],
	'created' => mysql_now(),
	'password' => $config['hash'],
	'user_code' => crypto_token(48),
	'referrer_code' => "created from host",
	'email_verified' => 1,
	'admin' => $config['level']
));
