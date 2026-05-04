<?php

/**
 * Coverage for RunController::personalizeManifest().
 *
 * The method is private; we reach in via Reflection. Both the
 * controller and the Run model are constructor-heavy (Site reference,
 * DB load), so we instantiate both with newInstanceWithoutConstructor()
 * and only set the public properties the method actually reads
 * (\$run->name).
 *
 * The exact host/path of the resulting URL depends on dev settings, so
 * assertions key on shape (does the URL end with the right query
 * suffix? do existing queries get & rather than ?) instead of absolute
 * URLs.
 */
class PwaPersonalizeManifestTest extends \PHPUnit\Framework\TestCase
{
    /** @var \ReflectionMethod */
    private $personalize;
    /** @var RunController */
    private $controller;
    /** @var Run */
    private $run;

    protected function setUp(): void
    {
        $this->controller = (new \ReflectionClass(RunController::class))
            ->newInstanceWithoutConstructor();
        $this->run = (new \ReflectionClass(Run::class))
            ->newInstanceWithoutConstructor();
        $this->run->name = 'pwa-test-run';

        $this->personalize = new \ReflectionMethod(
            RunController::class,
            'personalizeManifest'
        );
        $this->personalize->setAccessible(true);
    }

    /**
     * Invoke the private method. PHP's ReflectionMethod::invokeArgs
     * preserves the by-reference parameter so the caller sees mutations.
     */
    private function invoke(array $manifest, $code): array
    {
        $args = [&$manifest, $this->run, $code];
        $this->personalize->invokeArgs($this->controller, $args);
        return $manifest;
    }

    public function testTopLevelStartUrlAndIdAreTokenized(): void
    {
        $code = 'TESTCODE123';
        $out = $this->invoke([], $code);
        $this->assertArrayHasKey('start_url', $out);
        $this->assertArrayHasKey('id', $out);
        $this->assertSame($out['start_url'], $out['id'], 'id should equal start_url');
        $this->assertStringEndsWith('?code=' . $code, $out['start_url']);
        $this->assertStringContainsString('pwa-test-run', $out['start_url']);
    }

    public function testShortcutWithoutQueryGetsQuestionMarkSuffix(): void
    {
        $code = 'CODE1';
        $out = $this->invoke([
            'shortcuts' => [
                ['name' => 'Settings', 'url' => 'https://example.com/run/settings/'],
            ],
        ], $code);
        $this->assertSame(
            'https://example.com/run/settings/?code=' . $code,
            $out['shortcuts'][0]['url']
        );
    }

    public function testShortcutWithExistingQueryGetsAmpersandSuffix(): void
    {
        $code = 'CODE2';
        $out = $this->invoke([
            'shortcuts' => [
                ['name' => 'X', 'url' => 'https://example.com/run/?foo=bar'],
            ],
        ], $code);
        $this->assertSame(
            'https://example.com/run/?foo=bar&code=' . $code,
            $out['shortcuts'][0]['url']
        );
    }

    public function testProtocolHandlerWithExistingQueryGetsAmpersandSuffix(): void
    {
        // Mirrors the shape in templates/run/manifest_template.json:
        //   "url": "{START_URL}?pwa=true&query=%s"
        // After generateManifest() expands {START_URL}, we get a URL
        // with `?pwa=true&query=%s` already present.
        $code = 'CODE3';
        $out = $this->invoke([
            'protocol_handlers' => [
                [
                    'protocol' => 'web+formrtest',
                    'url' => 'https://example.com/run/?pwa=true&query=%s',
                ],
            ],
        ], $code);
        $this->assertSame(
            'https://example.com/run/?pwa=true&query=%s&code=' . $code,
            $out['protocol_handlers'][0]['url']
        );
    }

    public function testCodeWithSpecialCharsIsUrlEncoded(): void
    {
        // The configured user_code_regular_expression allows `+` and `~`
        // among others — characters that need URL-encoding in a query
        // value. Validate via urlencode() round-trip, not a hardcoded
        // expectation, so this stays right if PHP's urlencode policy
        // ever shifts.
        $code = 'a+b~c';
        $out = $this->invoke([
            'shortcuts' => [
                ['url' => 'https://example.com/run/path/'],
            ],
        ], $code);
        $expected = 'https://example.com/run/path/?code=' . urlencode($code);
        $this->assertSame($expected, $out['shortcuts'][0]['url']);
        $this->assertStringEndsWith('?code=' . urlencode($code), $out['start_url']);
    }

    public function testManifestWithoutShortcutsOrProtocolHandlersIsHandled(): void
    {
        // The template currently always includes both arrays, but a
        // future admin-edited manifest_json textarea content may strip
        // them. Don't crash.
        $out = $this->invoke([
            'name' => 'Just-name',
            'theme_color' => '#2196F3',
        ], 'C4');
        $this->assertArrayNotHasKey('shortcuts', $out);
        $this->assertArrayNotHasKey('protocol_handlers', $out);
        $this->assertSame('Just-name', $out['name']);
        $this->assertStringEndsWith('?code=C4', $out['start_url']);
    }

    public function testNonStringShortcutUrlIsLeftUntouched(): void
    {
        // Defensive: the appendCode closure short-circuits on
        // non-strings. A malformed manifest with a numeric url (or
        // missing url key entirely) shouldn't crash.
        $out = $this->invoke([
            'shortcuts' => [
                ['name' => 'broken', 'url' => 12345],
                ['name' => 'no-url-key'],
            ],
        ], 'C5');
        $this->assertSame(12345, $out['shortcuts'][0]['url']);
        $this->assertArrayNotHasKey('url', $out['shortcuts'][1]);
    }

    public function testStartUrlOverwritesExistingValue(): void
    {
        // Source manifest already has start_url (the on-disk file does).
        // Verify we OVERWRITE it rather than appending to it (would
        // produce ?code=X?code=Y or similar nonsense).
        $code = 'OVERWRITE1';
        $out = $this->invoke([
            'start_url' => 'https://example.com/run/',
            'id' => 'https://example.com/run/',
        ], $code);
        $this->assertStringEndsWith('?code=' . $code, $out['start_url']);
        $this->assertStringNotContainsString('?code=' . $code . '?code=', $out['start_url']);
        $this->assertSame($out['start_url'], $out['id']);
    }
}
