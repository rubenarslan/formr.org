<?php

/**
 * Coverage for user_code_html_pattern() in application/Functions.php.
 *
 * The helper derives an HTML5 `pattern` attribute string from the
 * configured `user_code_regular_expression` PHP regex so the PWA
 * recovery banner + server-rendered recovery form get accurate
 * client-side validation no matter how a deployment customizes the
 * regex. Implemented as: read Config, strip the delimiters + flags,
 * strip ^/$ anchors. Returns '' on any unrecognized shape so the
 * caller can omit the attribute and let server-side validation be the
 * only authority.
 *
 * Each test temporarily mutates Config via reflection, then restores it.
 */
class UserCodeHtmlPatternTest extends \PHPUnit\Framework\TestCase
{
    /** @var string */
    private $original;

    protected function setUp(): void
    {
        $this->original = (string) Config::get('user_code_regular_expression');
    }

    protected function tearDown(): void
    {
        $this->setRegex($this->original);
    }

    /** Set the runtime config without touching settings.php. */
    private function setRegex($regex): void
    {
        // Config stores merged settings as Config::$config (private
        // static) AND memoizes per-key reads in Config::$computed; we
        // must invalidate the memoized entry when mutating, or all the
        // tests in a single run see the first value Config::get returned.
        $ref = new \ReflectionClass(Config::class);
        $configProp = $ref->getProperty('config');
        $configProp->setAccessible(true);
        $current = $configProp->getValue(null);
        if (!is_array($current)) {
            $current = [];
        }
        if ($regex === null) {
            unset($current['user_code_regular_expression']);
        } else {
            $current['user_code_regular_expression'] = $regex;
        }
        $configProp->setValue(null, $current);

        $computedProp = $ref->getProperty('computed');
        $computedProp->setAccessible(true);
        $computed = $computedProp->getValue(null);
        if (is_array($computed)) {
            unset($computed['user_code_regular_expression']);
            $computedProp->setValue(null, $computed);
        }
    }

    public function testStripsSlashDelimitersAndAnchors(): void
    {
        $this->setRegex('/^[A-Za-z0-9]{64}$/');
        $this->assertSame('[A-Za-z0-9]{64}', user_code_html_pattern());
    }

    public function testStripsHashDelimitersAndFlags(): void
    {
        $this->setRegex('#^[A-Za-z0-9_-]{32}$#i');
        $this->assertSame('[A-Za-z0-9_-]{32}', user_code_html_pattern());
    }

    public function testStripsTildeDelimiters(): void
    {
        $this->setRegex('~^[a-z0-9]{8,}$~');
        $this->assertSame('[a-z0-9]{8,}', user_code_html_pattern());
    }

    public function testRegexWithoutAnchorsIsReturnedAsIs(): void
    {
        // HTML5 pattern is auto-anchored, so a regex without explicit
        // ^/$ should still produce a usable pattern string.
        $this->setRegex('/[A-Z]{4}/');
        $this->assertSame('[A-Z]{4}', user_code_html_pattern());
    }

    public function testPreservesAnchorsThatLiveInsideTheBody(): void
    {
        // Only the leading-^ and trailing-$ are stripped. Anchors that
        // appear elsewhere (e.g. inside an alternation) survive.
        $this->setRegex('/^foo\$bar$/');
        $this->assertSame('foo\$bar', user_code_html_pattern());
    }

    public function testReturnsEmptyOnUnrecognizedShape(): void
    {
        // No delimiters → don't try to guess. Caller falls back to no
        // pattern attribute, server-side validation stays authoritative.
        $this->setRegex('plain string with no slashes');
        $this->assertSame('', user_code_html_pattern());
    }

    public function testReturnsEmptyWhenConfigMissing(): void
    {
        $this->setRegex(null);
        $this->assertSame('', user_code_html_pattern());
    }

    public function testReturnsEmptyWhenConfigIsEmptyString(): void
    {
        $this->setRegex('');
        $this->assertSame('', user_code_html_pattern());
    }

    public function testHandlesDefaultConfigShape(): void
    {
        // The shipped default in config-dist/settings.php at the time of
        // writing. Pin the expected output so a future config nudge that
        // breaks the helper trips a test.
        $this->setRegex('/^[A-Za-z0-9+-_~]{64}$/');
        $this->assertSame('[A-Za-z0-9+-_~]{64}', user_code_html_pattern());
    }
}
