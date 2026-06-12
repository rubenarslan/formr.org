<?php

/**
 * Unit tests for the admin-area Content-Security-Policy builder and the
 * violation-report field extraction (application/Csp.php).
 */
class CspTest extends PHPUnit\Framework\TestCase {

    private static $savedConfig;
    private static $savedComputed;

    public static function setUpBeforeClass(): void {
        self::$savedConfig = self::configProp('config');
        self::$savedComputed = self::configProp('computed');
    }

    public static function tearDownAfterClass(): void {
        self::configProp('config', self::$savedConfig);
        self::configProp('computed', self::$savedComputed);
    }

    /** Get or set a private static Config property (no public reset API). */
    private static function configProp($name, $value = null) {
        $prop = new ReflectionProperty(Config::class, $name);
        $prop->setAccessible(true);
        if (func_num_args() === 2) {
            $prop->setValue(null, $value);
            return null;
        }
        return $prop->getValue();
    }

    /** Point Config at the given csp_mode, clearing the computed-value cache. */
    private function setCspMode($mode) {
        $settings = $mode === null ? array() : array('csp_mode' => $mode);
        Config::initialize($settings);
        self::configProp('computed', array());
    }

    public function testModeDefaultsToReportOnlyWhenUnset() {
        $this->setCspMode(null);
        $this->assertSame('report-only', Csp::mode());
        $this->assertTrue(Csp::isEnabled());
    }

    public function testModeCoercesInvalidValuesToReportOnly() {
        // a typo like 'enforced' must not silently disable the policy
        $this->setCspMode('enforced');
        $this->assertSame('report-only', Csp::mode());
        $this->assertSame('Content-Security-Policy-Report-Only', Csp::headerName());
    }

    public function testModeOff() {
        $this->setCspMode('off');
        $this->assertSame('off', Csp::mode());
        $this->assertFalse(Csp::isEnabled());
        $this->assertNull(Csp::headerName());
    }

    public function testModeEnforce() {
        $this->setCspMode('enforce');
        $this->assertSame('enforce', Csp::mode());
        $this->assertTrue(Csp::isEnabled());
        $this->assertSame('Content-Security-Policy', Csp::headerName());
    }

    public function testModeReportOnlyHeaderName() {
        $this->setCspMode('report-only');
        $this->assertSame('Content-Security-Policy-Report-Only', Csp::headerName());
    }

    public function testBuildPolicyEmbedsNonceAndOmitsUnsafeScript() {
        $policy = Csp::buildPolicy('TESTNONCE123');
        $this->assertStringContainsString("script-src 'self' 'nonce-TESTNONCE123'", $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);
        // unsafe-inline appears for styles only, never in script-src
        foreach (explode('; ', $policy) as $directive) {
            if (strpos($directive, 'script-src') === 0) {
                $this->assertStringNotContainsString('unsafe-inline', $directive);
            }
        }
    }

    public function testBuildPolicyCoreDirectives() {
        $policy = Csp::buildPolicy('n');
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("base-uri 'self'", $policy);
        $this->assertStringContainsString("worker-src 'self' blob:", $policy);
        $this->assertStringContainsString('report-uri ' . Csp::REPORT_URI, $policy);
    }

    public function testExtractReportFieldsCspLevel2Wrapper() {
        $fields = Csp::extractReportFields(array('csp-report' => array(
            'document-uri' => 'https://admin.example/admin/run/',
            'violated-directive' => 'script-src',
            'blocked-uri' => 'inline',
            'source-file' => 'https://admin.example/x.js',
            'line-number' => 7,
        )));
        $this->assertSame('https://admin.example/admin/run/', $fields['document-uri']);
        $this->assertSame('script-src', $fields['violated-directive']);
        $this->assertSame('inline', $fields['blocked-uri']);
        $this->assertSame('https://admin.example/x.js', $fields['source-file']);
        $this->assertSame(7, $fields['line-number']);
    }

    public function testExtractReportFieldsReportingApiBody() {
        $fields = Csp::extractReportFields(array(
            'type' => 'csp-violation',
            'body' => array(
                'documentURL' => 'https://admin.example/admin/',
                'effectiveDirective' => 'script-src-elem',
                'blockedURL' => 'eval',
                'sourceFile' => 'https://admin.example/y.js',
                'lineNumber' => 42,
            ),
        ));
        $this->assertSame('https://admin.example/admin/', $fields['document-uri']);
        $this->assertSame('script-src-elem', $fields['violated-directive']);
        $this->assertSame('eval', $fields['blocked-uri']);
        $this->assertSame('https://admin.example/y.js', $fields['source-file']);
        $this->assertSame(42, $fields['line-number']);
    }

    public function testExtractReportFieldsFlatBodyAndGarbage() {
        $flat = Csp::extractReportFields(array('effectiveDirective' => 'img-src', 'blockedURL' => 'https://evil.example/p.png'));
        $this->assertSame('img-src', $flat['violated-directive']);
        $this->assertSame('https://evil.example/p.png', $flat['blocked-uri']);

        $garbage = Csp::extractReportFields(array('unrelated' => str_repeat('x', 100)));
        $this->assertSame(
            array('document-uri', 'violated-directive', 'blocked-uri', 'source-file', 'line-number'),
            array_keys($garbage)
        );
        $this->assertSame(array(null, null, null, null, null), array_values($garbage));
    }
}
