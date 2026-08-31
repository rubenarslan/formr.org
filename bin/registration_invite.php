#!/usr/bin/php
<?php
/**
 * Issue / revoke / list single-use signup invitation tokens
 * (survey_registration_invites) for registration.mode = 'invite'. Only a
 * sha256 of each token is stored — copy the printed token when issuing; it
 * cannot be recovered later. Invitees enter it in the token field of the
 * signup form.
 *
 *   php bin/registration_invite.php -a issue [-n 3] [-d 30]   # -d = expires in days, 0 = never
 *   php bin/registration_invite.php -a revoke -t <token>
 *   php bin/registration_invite.php -a list
 */
require_once dirname(__FILE__) . '/../setup.php';

$opts = getopt('a:n:d:t:');
$action = isset($opts['a']) ? $opts['a'] : null;
$db = DB::getInstance();

function invite_usage() {
    fwrite(STDERR, "Usage: php bin/registration_invite.php -a issue|revoke|list [-n count] [-d expiry_days] [-t token]\n");
    exit(1);
}

switch ($action) {
    case 'issue':
        $count = isset($opts['n']) ? max(1, (int) $opts['n']) : 1;
        $days = isset($opts['d']) ? (int) $opts['d'] : 30;
        $expires = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
        $createdBy = 'cli:' . (getenv('SUDO_USER') ?: getenv('USER') ?: 'unknown');
        for ($i = 0; $i < $count; $i++) {
            $token = crypto_token(24);
            $db->insert('survey_registration_invites', array(
                'token_hash' => hash('sha256', $token),
                'created_by' => $createdBy,
                'created' => mysql_now(),
                'expires' => $expires,
            ));
            print $token . "\n";
        }
        fwrite(STDERR, $count . " invite(s) issued" . ($expires ? ", expiring {$expires}" : ", no expiry") . ".\n");
        break;

    case 'revoke':
        if (empty($opts['t'])) {
            invite_usage();
        }
        $deleted = $db->exec(
            'DELETE FROM survey_registration_invites WHERE token_hash = :token_hash AND used_at IS NULL',
            array('token_hash' => hash('sha256', trim($opts['t'])))
        );
        print $deleted ? "Invite revoked.\n" : "No unused invite matches that token.\n";
        break;

    case 'list':
        $rows = $db->execute('SELECT id, created_by, created, expires, used_at, used_by FROM survey_registration_invites ORDER BY id');
        if (!$rows) {
            print "No invites issued.\n";
            break;
        }
        printf("%-5s %-20s %-19s %-19s %-19s %s\n", 'id', 'created_by', 'created', 'expires', 'used_at', 'used_by');
        foreach ($rows as $row) {
            printf("%-5d %-20s %-19s %-19s %-19s %s\n", $row['id'], (string) $row['created_by'], $row['created'],
                (string) $row['expires'], (string) $row['used_at'], (string) $row['used_by']);
        }
        break;

    default:
        invite_usage();
}
