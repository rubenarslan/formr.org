#!/usr/bin/php
<?php
/**
 * Live-MariaDB smoke for the outbound-mail guard (patch 076 + GuardedMailer
 * + MailGuardService). The SQLite PHPUnit lane (MailGuardServiceTest,
 * RegistrationGuardTest) covers the decision logic; this smoke proves the
 * wiring against a real schema:
 *
 *  A. the 076 tables exist;
 *  B. suppression round trip refuses on every category;
 *  C. duplicate-send window refuses a second verification mail;
 *  D. per-IP limit and global ceiling trip at their configured defaults;
 *  E. Site::makeAdminMailer() hands back a GuardedMailer whose send() is
 *     refused for a suppressed recipient BEFORE any SMTP happens, and the
 *     refusal lands in survey_mail_guard_log;
 *  F. EmailDomainService verdicts + cache rows against live DNS.
 *
 * Usage:  docker exec formr_app php bin/test_mail_guard_smoke.php
 * Inserts only rows tagged with a per-run marker; cleans up in finally.
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();
$failures = 0;
function ok($cond, string $label): void {
    global $failures;
    echo $cond ? "  \e[32mOK\e[0m  {$label}\n" : "  \e[31mFAIL\e[0m {$label}\n";
    if (!$cond) { $GLOBALS['failures']++; }
}

$marker = 'mg-smoke-' . getmypid();
$addr = function ($local) use ($marker) { return "{$marker}-{$local}@example.org"; };
$T = MailGuardService::CATEGORY_TRANSACTIONAL;

try {
    echo "A. migration 076 tables\n";
    foreach (array('survey_mail_guard_log', 'survey_mail_suppressions',
                   'survey_registration_invites', 'survey_mail_domain_cache') as $t) {
        ok($db->table_exists($t), "table {$t} exists");
    }
    if ($failures) {
        echo "Guard tables missing — apply sql/patches/076_mail_guard.sql first.\n";
        exit(1);
    }

    echo "B. suppression round trip\n";
    ok(MailGuardService::suppress($addr('Victim'), 'smoke', 'smoke'), 'suppress() accepts the address');
    foreach (array($T, MailGuardService::CATEGORY_SYSTEM, MailGuardService::CATEGORY_STUDY) as $cat) {
        $v = MailGuardService::check($addr('victim'), 'verification', $cat, null);
        ok(!$v['allowed'] && $v['outcome'] === MailGuardService::OUTCOME_SUPPRESSED, "refused as {$cat}");
    }
    MailGuardService::unsuppress($addr('victim'));
    $v = MailGuardService::check($addr('victim'), 'account_test', MailGuardService::CATEGORY_SYSTEM, null);
    ok($v['allowed'], 'allowed again after unsuppress');

    echo "C. duplicate-send window\n";
    MailGuardService::record($addr('dupe'), 'verification', $T, null, null, MailGuardService::OUTCOME_SENT);
    $v = MailGuardService::check($addr('dupe'), 'verification', $T, null);
    ok(!$v['allowed'] && $v['outcome'] === MailGuardService::OUTCOME_DUPLICATE, 'second verification within 24h refused');
    ok(MailGuardService::check($addr('dupe'), 'password_reset', $T, null)['allowed'], 'other type unaffected');

    echo "D. rate ceilings at config defaults\n";
    $ip = '203.0.113.199'; // TEST-NET-3, never a real client
    $ipLimit = (int) Config::get('mail_guard.ip_limit_per_hour', 10);
    for ($i = 0; $i < $ipLimit; $i++) {
        MailGuardService::record($addr("ip{$i}"), 'verification', $T, $ip, null, MailGuardService::OUTCOME_SENT);
    }
    $v = MailGuardService::check($addr('ip-next'), 'verification', $T, $ip);
    ok(!$v['allowed'] && $v['outcome'] === MailGuardService::OUTCOME_IP_LIMIT, "attempt {$ipLimit}+1 from one IP refused");

    $global = (int) Config::get('mail_guard.global_limit_per_hour', 50);
    // top up to the global ceiling with IP-less sends (cron-style rows do not
    // carry an IP, so the per-IP check cannot fire first)
    $existing = (int) $db->execute(
        "SELECT COUNT(*) FROM survey_mail_guard_log WHERE category = 'transactional' AND outcome = 'sent' AND created >= :cutoff",
        array(':cutoff' => date('Y-m-d H:i:s', time() - 3600)), true);
    for ($i = $existing; $i < $global; $i++) {
        MailGuardService::record($addr("glob{$i}"), 'password_reset', $T, null, null, MailGuardService::OUTCOME_SENT);
    }
    $v = MailGuardService::check($addr('glob-next'), 'password_reset', $T, null);
    ok(!$v['allowed'] && $v['outcome'] === MailGuardService::OUTCOME_GLOBAL_LIMIT, "global ceiling ({$global}/h) refused");
    ok(MailGuardService::check($addr('sys'), 'cron', MailGuardService::CATEGORY_SYSTEM, null)['allowed'],
        'system mail unaffected by the ceiling');

    echo "E. GuardedMailer is the admin-mailer choke point\n";
    $mailer = Site::getInstance()->makeAdminMailer();
    ok($mailer instanceof GuardedMailer, 'makeAdminMailer() returns GuardedMailer');
    ok($mailer->guard_category === $T, 'defaults to the strictest category');
    MailGuardService::suppress($addr('victim'), 'smoke', 'smoke');
    $mailer->guard_type = 'verification';
    $mailer->AddAddress($addr('victim'));
    $mailer->Subject = 'smoke';
    $mailer->Body = 'smoke';
    $sent = $mailer->Send(); // legacy casing on purpose — must hit the override
    ok($sent === false && $mailer->guard_blocked === true, 'send refused before SMTP');
    ok($mailer->guard_outcome === MailGuardService::OUTCOME_SUPPRESSED, 'outcome is refused_suppressed');
    $row = $db->findRow('survey_mail_guard_log',
        array('recipient' => strtolower($addr('victim')), 'outcome' => MailGuardService::OUTCOME_SUPPRESSED));
    ok(!empty($row), 'refusal recorded in survey_mail_guard_log');

    echo "F. domain plausibility + cache\n";
    ok(EmailDomainService::isDeliverableDomain('gmail.com'), 'gmail.com is deliverable');
    $ghost = $marker . '-no-such-domain.invalid';
    ok(!EmailDomainService::isDeliverableDomain($ghost), "{$ghost} is not");
    ok((bool) $db->findRow('survey_mail_domain_cache', array('domain' => 'gmail.com')), 'verdict cached');
} finally {
    $db->exec("DELETE FROM survey_mail_guard_log WHERE recipient LIKE :m", array('m' => "{$marker}-%"));
    $db->exec("DELETE FROM survey_mail_suppressions WHERE address LIKE :m", array('m' => "{$marker}-%"));
    $db->exec("DELETE FROM survey_mail_domain_cache WHERE domain LIKE :m", array('m' => "{$marker}-%"));
}

echo $failures ? "\n\e[31m{$failures} failure(s)\e[0m\n" : "\n\e[32mAll mail-guard smoke checks passed.\e[0m\n";
exit($failures ? 1 : 0);
