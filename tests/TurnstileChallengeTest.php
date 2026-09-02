<?php

/**
 * Turnstile driver policy: only Cloudflare's own "success": false fails
 * closed; unreachable / 5xx / no-verdict answers fail open, and a missing
 * token with keys configured is refused. The siteverify transport is
 * scripted via a subclass so no network is touched. Also pins the CSP
 * widening the widget needs (script/frame/connect for the Cloudflare origin,
 * nonce kept on the script tag).
 */
class TurnstileChallengeTest extends PHPUnit\Framework\TestCase {

    protected function setUp(): void {
        $this->configure(array(
            'turnstile_site_key' => '1x00000000000000000000AA',
            'turnstile_secret_key' => '1x0000000000000000000000000000000AA',
        ));
        Csp::resetExtra();
    }

    protected function tearDown(): void {
        Csp::resetExtra();
    }

    protected function configure(array $registration) {
        $computed = new ReflectionProperty('Config', 'computed');
        $computed->setAccessible(true);
        $computed->setValue(null, array());
        $config = new ReflectionProperty('Config', 'config');
        $config->setAccessible(true);
        Config::initialize(array_merge((array) $config->getValue(null), array(
            'registration' => array_merge(array('mode' => 'open', 'challenge' => 'turnstile'), $registration),
        )));
    }

    protected function request($token = 'tok') {
        return new Request(array(TurnstileChallenge::FIELD => $token));
    }

    /** A driver whose siteverify answer is scripted. */
    protected function driver($status, $body = '', $throw = null) {
        return new class($status, $body, $throw) extends TurnstileChallenge {
            public $posted = null;
            private $status; private $body; private $throw;
            public function __construct($status, $body, $throw) {
                $this->status = $status; $this->body = $body; $this->throw = $throw;
            }
            protected function siteverify(array $post) {
                $this->posted = $post;
                if ($this->throw) {
                    throw new Exception($this->throw);
                }
                return array('status' => $this->status, 'body' => $this->body);
            }
        };
    }

    public function testCloudflareVerdictIsFinal() {
        $driver = $this->driver(200, '{"success":true}');
        $this->assertTrue($driver->verifyRequest($this->request('good')));
        $this->assertSame('good', $driver->posted['response']);
        $this->assertSame('1x0000000000000000000000000000000AA', $driver->posted['secret']);

        $this->assertFalse($this->driver(200, '{"success":false,"error-codes":["invalid-input-response"]}')
            ->verifyRequest($this->request()), 'Cloudflare said no');
        $this->assertFalse($this->driver(200, '{"success":false,"error-codes":["timeout-or-duplicate"]}')
            ->verifyRequest($this->request()), 'a spent token is refused');
    }

    public function testMissingTokenIsRefusedWhenConfigured() {
        $driver = $this->driver(200, '{"success":true}');
        $this->assertFalse($driver->verifyRequest(new Request(array())));
        $this->assertNull($driver->posted, 'nothing to verify, no round trip');
    }

    public function testUpstreamTroubleFailsOpen() {
        $this->assertTrue($this->driver(0, '', 'connect timeout')->verifyRequest($this->request()), 'transport exception');
        $this->assertTrue($this->driver(503, 'upstream error')->verifyRequest($this->request()), '5xx');
        $this->assertTrue($this->driver(0, '')->verifyRequest($this->request()), 'no HTTP status');
        $this->assertTrue($this->driver(200, '<html>not json</html>')->verifyRequest($this->request()), 'unparseable');
        $this->assertTrue($this->driver(200, '{"challenge_ts":"x"}')->verifyRequest($this->request()), 'no verdict field');
    }

    public function testUnconfiguredDriverIsInert() {
        $this->configure(array('turnstile_site_key' => '', 'turnstile_secret_key' => ''));
        $driver = $this->driver(200, '{"success":false}');
        $this->assertSame('', $driver->widgetHtml());
        $this->assertTrue($driver->verifyRequest(new Request(array())), 'no keys: nothing rendered, nothing required');
        $this->assertNull($driver->posted);
        $this->assertStringNotContainsString('cloudflare', Csp::buildPolicy('n'), 'no widget, no CSP widening');
    }

    public function testWidgetCarriesNonceAndWidensCsp() {
        $html = (new TurnstileChallenge())->widgetHtml();
        $nonce = Site::getInstance()->getCspNonce();
        $this->assertStringContainsString('src="' . TurnstileChallenge::ORIGIN . '/turnstile/v0/api.js"', $html);
        $this->assertStringContainsString('nonce="' . $nonce . '"', $html);
        $this->assertStringContainsString('class="form-group cf-turnstile"', $html);
        $this->assertStringContainsString('data-sitekey="1x00000000000000000000AA"', $html);

        $policy = Csp::buildPolicy($nonce);
        foreach (array('script-src', 'frame-src', 'connect-src') as $directive) {
            $this->assertMatchesRegularExpression('/(^|; )' . $directive . ' [^;]*' . preg_quote(TurnstileChallenge::ORIGIN, '/') . '/', $policy, $directive);
        }
        $this->assertDoesNotMatchRegularExpression('/(^|; )default-src [^;]*cloudflare/', $policy, 'default-src stays tight');
        $this->assertStringContainsString("'nonce-" . $nonce . "'", $policy);
    }

}
