#!/usr/bin/php
<?php
/**
 * Manage the outbound-mail suppression list (survey_mail_suppressions) so an
 * operator can act on a complaint immediately, without touching SQL. Every
 * send on every path (registration, password reset, cron, study mail) checks
 * this list before anything leaves the process.
 *
 *   php bin/suppression.php -a add -e victim@example.com -r "complained via provider"
 *   php bin/suppression.php -a remove -e victim@example.com
 *   php bin/suppression.php -a list
 */
require_once dirname(__FILE__) . '/../setup.php';

$opts = getopt('a:e:r:');
$action = isset($opts['a']) ? $opts['a'] : null;

function suppression_usage() {
    fwrite(STDERR, "Usage: php bin/suppression.php -a add|remove|list [-e email] [-r reason]\n");
    exit(1);
}

switch ($action) {
    case 'add':
        if (empty($opts['e'])) {
            suppression_usage();
        }
        $addedBy = 'cli:' . (getenv('SUDO_USER') ?: getenv('USER') ?: 'unknown');
        if (MailGuardService::suppress($opts['e'], isset($opts['r']) ? $opts['r'] : null, $addedBy)) {
            print "Suppressed: " . MailGuardService::normalizeAddress($opts['e']) . "\n";
        } else {
            fwrite(STDERR, "Not a valid email address: {$opts['e']}\n");
            exit(1);
        }
        break;

    case 'remove':
        if (empty($opts['e'])) {
            suppression_usage();
        }
        $removed = MailGuardService::unsuppress($opts['e']);
        print $removed ? "Removed: " . MailGuardService::normalizeAddress($opts['e']) . "\n"
                       : "No suppression entry for: {$opts['e']}\n";
        break;

    case 'list':
        $rows = MailGuardService::listSuppressions();
        if (!$rows) {
            print "Suppression list is empty.\n";
            break;
        }
        foreach ($rows as $row) {
            printf("%-50s %-19s %-20s %s\n", $row['address'], $row['created'],
                (string) $row['added_by'], (string) $row['reason']);
        }
        break;

    default:
        suppression_usage();
}
