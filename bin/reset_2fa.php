#!/usr/bin/php
<?php
/**
 * Command line script to reset 2FA for any user
 * Usage: php bin/reset_2fa.php <email>
 * If no email is provided, it will list all users with 2FA enabled
 */
require_once dirname(__FILE__) . '/../setup.php';

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line\n");
}

function printUsage() {
    echo "Usage: php bin/reset_2fa.php [email]\n";
    echo "If no email is provided, lists all users with 2FA enabled\n";
    exit(1);
}

// Get email from command line arguments
$email = null;
if ($argc > 1) {
    // Get the last argument (in case of docker exec adding extra args)
    $email = trim($argv[$argc - 1]);
    // If it's a help flag, show usage
    if (in_array($email, array('-h', '--help'))) {
        printUsage();
    }
}

$db = DB::getInstance();

// List all users with 2FA enabled if no email provided
if (!$email) {
    $users = $db->query("SELECT email, admin FROM survey_users WHERE 2fa_code IS NOT NULL AND 2fa_code != '' ORDER BY email");
    if (empty($users)) {
        echo "No users have 2FA enabled.\n";
        exit(0);
    }

    echo "Users with 2FA enabled:\n";
    foreach ($users as $user) {
        echo sprintf("- %s (admin level: %d)\n", $user['email'], $user['admin']);
    }
    exit(0);
}

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("Error: Invalid email address '{$email}'\n");
}

// Find user ID first
$user_data = $db->select('id')
->from('survey_users')
->where(array('email' => $email))
->limit(1)
->fetch();
if(!$user_data) {
    die("Error: User not found for email '{$email}'\n");
}

// Create user instance with ID
$user = new User($user_data['id']);
if (!$user->is2FAenabled()) {
    die("Error: User does not have 2FA enabled for email '{$email}'\n");
}

// Confirm action
echo "WARNING: This will reset 2FA for user {$email}\n";
echo "The user will need to set up 2FA again to use it.\n";
echo "Are you sure? [y/N] ";

$handle = fopen("php://stdin", "r");
$line = fgets($handle);
if (strtolower(trim($line)) != 'y') {
    echo "Aborted\n";
    exit(1);
}

// Reset 2FA
if ($user->reset2FA()) {
    echo "Successfully reset 2FA for {$email}. Login in again to set up 2FA again.\n";
    exit(0);
} else {
    echo "Error: Failed to reset 2FA\n";
    exit(1);
} 