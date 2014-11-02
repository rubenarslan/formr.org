<?php

$settings['opencpu_instance'] = 'https://public.opencpu.org';
$settings['alternative_opencpu_instance'] = 'https://public.opencpu.org'; # used in admin/test_opencpu


$settings['email']['host'] = ''; # smtp server that you want to use, you can prefix a protocol like ssl://
$settings['email']['port'] = 587; # its port
$settings['email']['tls'] = true; # whether to use TLS
$settings['email']['from'] = ''; # the email address from which to send mail
$settings['email']['from_name'] = ''; # the name to display as sender
$settings['email']['username'] = ''; # the username
$settings['email']['password'] = ''; # the password

$settings['display_errors_when_live'] = 0; // should PHP and MySQL errors be displayed to the users when formr is not running locally? If 0, they are only logged

$settings['timezone'] = 'Europe/Berlin';

$settings['expire_unregistered_session'] = 365 * 24 * 60 * 60; # for unregistered users. in seconds (defaults to a year)
$settings['expire_registered_session'] = 7 * 24 * 60 * 60; # for registered users. in seconds (defaults to a week)
$settings['expire_admin_session'] = 7 * 24 * 60 * 60; # for admins. in seconds (defaults to a week). has to be lower than the expiry for registered users.
$settings['session_cookie_lifetime'] = max($settings['expire_unregistered_session'], $settings['expire_registered_session'], $settings['expire_admin_session']); # upper limit for all values above (defaults to their max)

$settings['admin_maximum_size_of_uploaded_files'] = 50; # in MB

$settings['run_exports_dir'] = INCLUDE_ROOT . 'exports/runs';