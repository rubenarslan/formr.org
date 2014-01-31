<?php
$settings['opencpu_instance'] = 'https://public.opencpu.org/';
$settings['alternative_opencpu_instance'] = 'https://public.opencpu.org/'; # used in admin/test_opencpu


$settings['email']['host'] = ''; # smtp server that you want to use, you can prefix a protocol like ssl://
$settings['email']['port'] = 587; # its port
$settings['email']['tls'] = true; # whether to use TLS
$settings['email']['from'] = ''; # the email address from which to send mail
$settings['email']['from_name'] = ''; # the name to display as sender
$settings['email']['username'] = ''; # the username
$settings['email']['password'] = ''; # the password

$settings['display_errors_when_live'] = 0; // should PHP and MySQL errors be displayed to the users when formr is not running locally? If 0, they are only logged

$settings['timezone'] = 'Europe/Berlin';