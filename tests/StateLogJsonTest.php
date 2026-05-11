<?php

/**
 * Track A A6 / D5 — UnitSession::buildStateLog produces the documented
 * JSON shape used by analysis tooling and the admin queue inspector.
 *
 * Pins the JSON contract so consumers that read state_log don't break
 * silently when the writer drifts. The legacy `result_log` text column
 * keeps being written next to this for backwards compatibility.
 */
class StateLogJsonTest extends \PHPUnit\Framework\TestCase
{
    private function callBuildStateLog(string $reason, array $ctx = []): ?string
    {
        $us = (new ReflectionClass(UnitSession::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod(UnitSession::class, 'buildStateLog');
        $m->setAccessible(true);
        return $m->invoke($us, $reason, $ctx);
    }

    public function testBuildStateLogProducesDocumentedShape(): void
    {
        $json = $this->callBuildStateLog('email_sent', ['unit_type' => 'Email', 'msg' => 'OK']);
        $this->assertIsString($json);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);

        $this->assertSame('email_sent', $decoded['reason']);
        $this->assertSame('Email', $decoded['ctx']['unit_type']);
        $this->assertSame('OK', $decoded['ctx']['msg']);
        $this->assertArrayHasKey('at', $decoded);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $decoded['at'],
            'state_log.at must be an ISO 8601 timestamp'
        );
    }

    public function testBuildStateLogStripsEmptyContextValues(): void
    {
        $json = $this->callBuildStateLog('survey_ended', [
            'unit_type' => 'Survey',
            'msg'       => null,    // dropped
            'extra'     => '',       // dropped
            'kept'      => 0,        // kept (0 is falsy but explicit)
        ]);
        $decoded = json_decode($json, true);

        $this->assertSame('Survey', $decoded['ctx']['unit_type']);
        $this->assertArrayNotHasKey('msg', $decoded['ctx']);
        $this->assertArrayNotHasKey('extra', $decoded['ctx']);
        // 0 is intentionally retained — the array_filter callback only
        // strips null and ''.
        $this->assertSame(0, $decoded['ctx']['kept']);
    }

    public function testBuildStateLogReturnsNullForEmptyReason(): void
    {
        $this->assertNull($this->callBuildStateLog(''));
    }

    /**
     * Sanity: encoding doesn't escape forward slashes, so URL/file-path
     * payloads round-trip cleanly through JSON_UNESCAPED_SLASHES.
     */
    public function testBuildStateLogPreservesSlashes(): void
    {
        $json = $this->callBuildStateLog('redirect', ['url' => 'https://example.com/x']);
        $this->assertStringContainsString('https://example.com/x', $json);
        $this->assertStringNotContainsString('https:\/\/example.com\/x', $json);
    }
}
