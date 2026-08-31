<?php

/**
 * Send-path guard tests: suppressed address, duplicate window, per-IP /
 * per-subnet / global ceilings, MX rejection, fail-open.
 *
 * Runs on the SQLite bootstrap: MailGuardService computes its time-window
 * cutoffs in PHP (not DATE_SUB) precisely so the same queries run under both
 * MariaDB and SQLite. DNS is stubbed via MailGuardService::$domainChecker.
 * The wired-up end-to-end (controller + GuardedMailer + real MariaDB) is
 * covered by bin/test_mail_guard_smoke.php.
 */
class MailGuardServiceTest extends PHPUnit\Framework\TestCase {

    const T = MailGuardService::CATEGORY_TRANSACTIONAL;

    public static function setUpBeforeClass(): void {
        self::createTables();
    }

    protected static function createTables() {
        $pdo = DB::getInstance()->pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS survey_mail_guard_log (
            id INTEGER PRIMARY KEY, recipient TEXT, type TEXT, category TEXT,
            user_id INTEGER, ip TEXT, subnet TEXT, outcome TEXT, created TEXT)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS survey_mail_suppressions (
            id INTEGER PRIMARY KEY, address TEXT UNIQUE, reason TEXT, added_by TEXT, created TEXT)');
    }

    protected function setUp(): void {
        self::createTables();
        $pdo = DB::getInstance()->pdo();
        $pdo->exec('DELETE FROM survey_mail_guard_log');
        $pdo->exec('DELETE FROM survey_mail_suppressions');
        $this->configure(array());
        MailGuardService::$domainChecker = null;
    }

    protected function tearDown(): void {
        MailGuardService::$domainChecker = null;
    }

    /** Reset Config (including its memoized lookups) with mail_guard overrides. */
    protected function configure(array $mailGuard) {
        $computed = new ReflectionProperty('Config', 'computed');
        $computed->setAccessible(true);
        $computed->setValue(null, array());
        $config = new ReflectionProperty('Config', 'config');
        $config->setAccessible(true);
        Config::initialize(array_merge((array) $config->getValue(null), array(
            'mail_guard' => array_merge(array('enabled' => true, 'mx_check' => false), $mailGuard),
        )));
    }

    /** Insert a guard-log row directly, defaulting to "sent verification just now". */
    protected function insertLog(array $overrides = array()) {
        DB::getInstance()->insert('survey_mail_guard_log', array_merge(array(
            'recipient' => 'someone@example.org',
            'type' => 'verification',
            'category' => self::T,
            'user_id' => null,
            'ip' => null,
            'subnet' => null,
            'outcome' => MailGuardService::OUTCOME_SENT,
            'created' => date('Y-m-d H:i:s'),
        ), $overrides));
    }

    public function testSuppressedAddressIsRefusedOnEveryCategory() {
        MailGuardService::suppress('Victim@Example.org', 'complained', 'test');
        foreach (array(self::T, MailGuardService::CATEGORY_SYSTEM, MailGuardService::CATEGORY_STUDY) as $category) {
            $verdict = MailGuardService::check('victim@example.org', 'verification', $category, null);
            $this->assertFalse($verdict['allowed'], "category $category");
            $this->assertEquals(MailGuardService::OUTCOME_SUPPRESSED, $verdict['outcome']);
        }
        MailGuardService::unsuppress('victim@example.org');
        $this->assertTrue(MailGuardService::check('victim@example.org', 'verification', self::T, null)['allowed']);
    }

    public function testDuplicateWithinWindowIsRefused() {
        $this->insertLog(array('recipient' => 'dupe@example.org', 'type' => 'verification'));
        $verdict = MailGuardService::check('dupe@example.org', 'verification', self::T, null);
        $this->assertFalse($verdict['allowed']);
        $this->assertEquals(MailGuardService::OUTCOME_DUPLICATE, $verdict['outcome']);
        // a different guarded type to the same address is not a duplicate
        $this->assertTrue(MailGuardService::check('dupe@example.org', 'password_reset', self::T, null)['allowed']);
        // outside the 24h window it is allowed again
        DB::getInstance()->pdo()->exec("UPDATE survey_mail_guard_log SET created = '"
            . date('Y-m-d H:i:s', time() - 25 * 3600) . "'");
        $this->assertTrue(MailGuardService::check('dupe@example.org', 'verification', self::T, null)['allowed']);
    }

    public function testRefusedAttemptDoesNotExtendDuplicateWindow() {
        $this->insertLog(array('recipient' => 'dupe@example.org', 'outcome' => MailGuardService::OUTCOME_GLOBAL_LIMIT));
        $this->assertTrue(MailGuardService::check('dupe@example.org', 'verification', self::T, null)['allowed']);
    }

    public function testPerIpLimitCountsAttempts() {
        $this->configure(array('ip_limit_per_hour' => 3, 'subnet_limit_per_hour' => 0, 'global_limit_per_hour' => 0));
        foreach (array('a', 'b', 'c') as $i => $who) {
            // refused attempts count toward the IP limit too — hammering is not free
            $this->insertLog(array('recipient' => "$who@example.org", 'ip' => '203.0.113.7',
                'subnet' => '203.0.113.0/24', 'outcome' => $i === 0 ? MailGuardService::OUTCOME_SENT : 'refused_honeypot'));
        }
        $verdict = MailGuardService::check('d@example.org', 'verification', self::T, '203.0.113.7');
        $this->assertFalse($verdict['allowed']);
        $this->assertEquals(MailGuardService::OUTCOME_IP_LIMIT, $verdict['outcome']);
        // a different IP in another subnet is unaffected
        $this->assertTrue(MailGuardService::check('d@example.org', 'verification', self::T, '198.51.100.9')['allowed']);
    }

    public function testPerSubnetLimit() {
        $this->configure(array('ip_limit_per_hour' => 100, 'subnet_limit_per_hour' => 3, 'global_limit_per_hour' => 0));
        foreach (array('4', '5', '6') as $last) {
            $this->insertLog(array('recipient' => "u$last@example.org", 'ip' => "203.0.113.$last", 'subnet' => '203.0.113.0/24'));
        }
        $verdict = MailGuardService::check('u7@example.org', 'verification', self::T, '203.0.113.7');
        $this->assertFalse($verdict['allowed']);
        $this->assertEquals(MailGuardService::OUTCOME_SUBNET_LIMIT, $verdict['outcome']);
        $this->assertTrue(MailGuardService::check('u7@example.org', 'verification', self::T, '203.0.114.7')['allowed']);
    }

    public function testGlobalCeilingCountsOnlySentMail() {
        $this->configure(array('ip_limit_per_hour' => 0, 'subnet_limit_per_hour' => 0, 'global_limit_per_hour' => 2));
        $this->insertLog(array('recipient' => 'a@example.org'));
        $this->insertLog(array('recipient' => 'b@example.org', 'type' => 'password_reset'));
        // refusals do not hold the ceiling down
        $this->insertLog(array('recipient' => 'c@example.org', 'outcome' => 'refused_honeypot'));
        $verdict = MailGuardService::check('d@example.org', 'verification', self::T, null);
        $this->assertFalse($verdict['allowed']);
        $this->assertEquals(MailGuardService::OUTCOME_GLOBAL_LIMIT, $verdict['outcome']);
        $this->assertTrue(MailGuardService::globalCeilingTripped(1) === false, 'refusal is recorded by caller, not check()');
    }

    public function testSystemAndStudyMailAreExemptFromCeilings() {
        $this->configure(array('ip_limit_per_hour' => 1, 'subnet_limit_per_hour' => 1, 'global_limit_per_hour' => 1));
        $this->insertLog(array('recipient' => 'a@example.org', 'ip' => '203.0.113.7', 'subnet' => '203.0.113.0/24'));
        $this->assertFalse(MailGuardService::check('b@example.org', 'verification', self::T, '203.0.113.7')['allowed']);
        $this->assertTrue(MailGuardService::check('b@example.org', 'cron', MailGuardService::CATEGORY_SYSTEM, '203.0.113.7')['allowed']);
        $this->assertTrue(MailGuardService::check('b@example.org', 'study', MailGuardService::CATEGORY_STUDY, '203.0.113.7')['allowed']);
    }

    public function testMxRejection() {
        $this->configure(array('mx_check' => true));
        MailGuardService::$domainChecker = function ($address) {
            return EmailDomainService::domainOf($address) !== 'nxdomain.tld';
        };
        $verdict = MailGuardService::check('x@nxdomain.tld', 'verification', self::T, null);
        $this->assertFalse($verdict['allowed']);
        $this->assertEquals(MailGuardService::OUTCOME_BAD_DOMAIN, $verdict['outcome']);
        $this->assertTrue(MailGuardService::check('x@example.org', 'verification', self::T, null)['allowed']);
        // study mail gets the domain check too (a typo'd domain only burns retries)
        $this->assertFalse(MailGuardService::check('x@nxdomain.tld', 'study', MailGuardService::CATEGORY_STUDY, null)['allowed']);
    }

    public function testRecordWritesOneRowWithSubnet() {
        MailGuardService::record('User@Example.org', 'verification', self::T, '203.0.113.7', 42, MailGuardService::OUTCOME_SENT);
        $row = DB::getInstance()->findRow('survey_mail_guard_log', array('recipient' => 'user@example.org'));
        $this->assertNotEmpty($row);
        $this->assertEquals('203.0.113.0/24', $row['subnet']);
        $this->assertEquals(42, $row['user_id']);
        $this->assertEquals('sent', $row['outcome']);
    }

    public function testSubnetKey() {
        $this->assertEquals('203.0.113.0/24', MailGuardService::subnetKey('203.0.113.77'));
        $this->assertEquals('2001:db8:a:b::/64', MailGuardService::subnetKey('2001:db8:a:b:c:d:e:f'));
        $this->assertNull(MailGuardService::subnetKey('not-an-ip'));
        $this->assertNull(MailGuardService::subnetKey(null));
    }

    public function testDisabledGuardAllowsEverything() {
        MailGuardService::suppress('victim@example.org', 'complained', 'test');
        $this->configure(array('enabled' => false));
        $this->assertTrue(MailGuardService::check('victim@example.org', 'verification', self::T, null)['allowed']);
    }

    public function testFailsOpenWhenTablesAreMissing() {
        $pdo = DB::getInstance()->pdo();
        $pdo->exec('DROP TABLE survey_mail_guard_log');
        $pdo->exec('DROP TABLE survey_mail_suppressions');
        // pre-076-migration state: the guard must not break mail delivery
        $verdict = MailGuardService::check('x@example.org', 'verification', self::T, '203.0.113.7');
        $this->assertTrue($verdict['allowed']);
        MailGuardService::record('x@example.org', 'verification', self::T, null, null, 'sent'); // must not throw
        self::createTables();
    }

}
