<?php

/**
 * Domain-deliverability policy: authoritative DNS answers (records present,
 * NXDOMAIN/NODATA everywhere, RFC 7505 null MX) decide and are cached;
 * transient failures (SERVFAIL/timeout) and a dead resolver fail open and
 * are neither cached nor memoised. DNS and the cache table are scripted via
 * a subclass so nothing leaves the process.
 */
class EmailDomainServiceTest extends PHPUnit\Framework\TestCase {

    public static function setUpBeforeClass(): void {
        DB::getInstance()->pdo()->exec('CREATE TABLE IF NOT EXISTS survey_mail_domain_cache (
            domain TEXT PRIMARY KEY, deliverable INTEGER, checked TEXT)');
    }

    protected function setUp(): void {
        EmailDomainService::resetMemo();
        ScriptedDomainService::reset();
        DB::getInstance()->pdo()->exec('DELETE FROM survey_mail_domain_cache');
    }

    public function testRecordsPresentIsDeliverableAndCached() {
        ScriptedDomainService::$dns['ok.example'] = true;
        $this->assertTrue(ScriptedDomainService::isDeliverableDomain('ok.example'));
        $this->assertSame(array('ok.example' => true), ScriptedDomainService::$cache);
        $this->assertSame(1, ScriptedDomainService::$lookups, 'one DNS round trip');
        $this->assertTrue(ScriptedDomainService::isDeliverableDomain('OK.example.'));
        $this->assertSame(1, ScriptedDomainService::$lookups, 'memoised (case/dot-insensitive)');
    }

    public function testAuthoritativeNegativeFailsClosedOnlyWithALiveResolver() {
        ScriptedDomainService::$dns['nx.example'] = false;
        ScriptedDomainService::$alive = true;
        $this->assertFalse(ScriptedDomainService::isDeliverableDomain('nx.example'));
        $this->assertSame(array('nx.example' => false), ScriptedDomainService::$cache, 'authoritative: cached');

        ScriptedDomainService::reset();
        EmailDomainService::resetMemo();
        ScriptedDomainService::$dns['nx.example'] = false;
        ScriptedDomainService::$alive = false;
        $this->assertTrue(ScriptedDomainService::isDeliverableDomain('nx.example'), 'resolver answers nothing: not evidence');
        $this->assertSame(array(), ScriptedDomainService::$cache, 'not cached');
        $this->assertTrue(ScriptedDomainService::isDeliverableDomain('nx.example'));
        $this->assertSame(2, ScriptedDomainService::$lookups, 'not memoised either: asked again');
    }

    public function testTransientFailureFailsOpenWithoutCaching() {
        ScriptedDomainService::$dns['flaky.example'] = null;
        ScriptedDomainService::$alive = true;
        $this->assertTrue(ScriptedDomainService::isDeliverableDomain('flaky.example'));
        $this->assertSame(array(), ScriptedDomainService::$cache);
        $this->assertTrue(ScriptedDomainService::isDeliverableDomain('flaky.example'));
        $this->assertSame(2, ScriptedDomainService::$lookups, 'asked again next time');

        // the resolver recovers: the real verdict lands
        ScriptedDomainService::$dns['flaky.example'] = false;
        $this->assertFalse(ScriptedDomainService::isDeliverableDomain('flaky.example'));
        $this->assertSame(array('flaky.example' => false), ScriptedDomainService::$cache);
    }

    public function testCachedVerdictSkipsDns() {
        ScriptedDomainService::$cache['cached.example'] = false;
        $this->assertFalse(ScriptedDomainService::isDeliverableDomain('cached.example'));
        $this->assertSame(0, ScriptedDomainService::$lookups);
    }

    public function testAddressParsing() {
        $this->assertSame('example.org', EmailDomainService::domainOf('Someone@Example.ORG.'));
        $this->assertNull(EmailDomainService::domainOf('no-at-sign'));
        $this->assertFalse(EmailDomainService::isDeliverableAddress('nobody@'));
        $this->assertFalse(EmailDomainService::isDeliverableDomain(''));
    }

    public function testNullMxMeansNoMail() {
        $m = new ReflectionMethod('EmailDomainService', 'isNullMx');
        $m->setAccessible(true);
        $this->assertTrue($m->invoke(null, array(array('target' => '.', 'pri' => 0))));
        $this->assertTrue($m->invoke(null, array(array('target' => '', 'pri' => 0))));
        $this->assertFalse($m->invoke(null, array(array('target' => 'mx.example.', 'pri' => 10))));
        $this->assertFalse($m->invoke(null, array(array('target' => '.', 'pri' => 0), array('target' => 'mx.example', 'pri' => 10))), 'a real exchange alongside the null one still accepts mail');
    }

    public function testPruneCacheDropsStaleRowsOnly() {
        $pdo = DB::getInstance()->pdo();
        $pdo->exec("INSERT INTO survey_mail_domain_cache VALUES ('old.example', 1, '" . date('Y-m-d H:i:s', time() - 3 * 86400) . "')");
        $pdo->exec("INSERT INTO survey_mail_domain_cache VALUES ('new.example', 0, '" . date('Y-m-d H:i:s') . "')");
        $this->assertSame(1, EmailDomainService::pruneCache());
        $this->assertSame('new.example', $pdo->query('SELECT domain FROM survey_mail_domain_cache')->fetchColumn());
    }

}

/** EmailDomainService with DNS and the cache table replaced by arrays. */
class ScriptedDomainService extends EmailDomainService {

    /** @var array<string,bool|null> domain => lookup outcome (true/false/null=transient) */
    public static $dns = array();
    /** @var array<string,bool> the "cache table" */
    public static $cache = array();
    public static $alive = true;
    public static $lookups = 0;

    public static function reset() {
        self::$dns = array();
        self::$cache = array();
        self::$alive = true;
        self::$lookups = 0;
    }

    protected static function lookupMailRecords($domain) {
        self::$lookups++;
        return array_key_exists($domain, self::$dns) ? self::$dns[$domain] : null;
    }

    protected static function resolverAlive() {
        return self::$alive;
    }

    protected static function cachedVerdict($domain) {
        return array_key_exists($domain, self::$cache) ? self::$cache[$domain] : null;
    }

    protected static function cacheVerdict($domain, $deliverable) {
        self::$cache[$domain] = (bool) $deliverable;
    }

}
