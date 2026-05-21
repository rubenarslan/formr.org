<?php
use PHPUnit\Framework\TestCase;

/**
 * Run::isReservedName is the gate that blocks `/api`, `/test` etc. from
 * being claimed as a study subdomain. Worth a small unit test now that we
 * touched the regex-delimiter handling.
 *
 * The function is static and DB-free, so we don't need to bootstrap DB
 * here — only Config (via Config::initialize) needs to know the reserved
 * list.
 */
class RunIsReservedNameTest extends TestCase
{
    private function setReserved(array $names)
    {
        Config::initialize(['reserved_run_names' => $names]);
        // Config::get() caches resolved values in a private static; without
        // resetting it, the first test's reserved list would persist into
        // subsequent tests and silently invalidate the assertions.
        $ref = new ReflectionClass(Config::class);
        $cache = $ref->getProperty('computed');
        $cache->setAccessible(true);
        $cache->setValue(null, []);
    }

    public function testExactMatchIsReserved()
    {
        $this->setReserved(['api', 'test', 'delegate']);
        $this->assertTrue(Run::isReservedName('api'));
        $this->assertTrue(Run::isReservedName('test'));
        $this->assertTrue(Run::isReservedName('delegate'));
    }

    public function testPrefixWithHyphenIsReserved()
    {
        $this->setReserved(['api']);
        // The original bug: `api-foo` and `api-2026` should be blocked since
        // they could shadow the `/api` endpoint when used as study slugs.
        $this->assertTrue(Run::isReservedName('api-foo'));
        $this->assertTrue(Run::isReservedName('api-2026'));
    }

    public function testNonPrefixSubstringIsNotReserved()
    {
        $this->setReserved(['api']);
        // `apifoo` shares letters but doesn't sit at the prefix boundary, so
        // it must be allowed — otherwise we'd block legitimate run names like
        // `application` or `apidemia`.
        $this->assertFalse(Run::isReservedName('apifoo'));
        $this->assertFalse(Run::isReservedName('application'));
        $this->assertFalse(Run::isReservedName('myapi'));
    }

    public function testCaseInsensitive()
    {
        $this->setReserved(['api']);
        $this->assertTrue(Run::isReservedName('API'));
        $this->assertTrue(Run::isReservedName('Api-foo'));
    }

    public function testNoReservedConfigReturnsFalse()
    {
        $this->setReserved([]);
        $this->assertFalse(Run::isReservedName('api'));
        $this->assertFalse(Run::isReservedName('anything'));
    }

    /**
     * Reserved names are short identifiers in practice, but the regex must
     * still survive any future entry containing a regex metacharacter — in
     * particular '/' (the delimiter we splice the alternation into) or '.'.
     * Without preg_quote(., '/') the alternation would parse '/' as the end
     * of the pattern.
     */
    public function testRegexMetacharactersInReservedNamesAreLiteral()
    {
        $this->setReserved(['ad/min', 'dot.path']);
        $this->assertTrue(Run::isReservedName('ad/min'));
        $this->assertTrue(Run::isReservedName('ad/min-x'));
        $this->assertTrue(Run::isReservedName('dot.path'));

        // The dot in 'dot.path' must NOT be interpreted as "any char" —
        // 'dotXpath' is unrelated and must be allowed.
        $this->assertFalse(Run::isReservedName('dotXpath'));
    }
}
