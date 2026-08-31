<?php

/**
 * Registration bot-friction and gating tests: honeypot, signed form
 * timestamp (too fast / expired / tampered), and the registration.mode gate
 * (open / domains / invite / closed). SQLite lane; the HMAC secret comes
 * from config so no crypto key file is needed.
 */
class RegistrationGuardTest extends PHPUnit\Framework\TestCase {

    const SECRET = 'test-secret-for-registration-guard';

    public static function setUpBeforeClass(): void {
        DB::getInstance()->pdo()->exec('CREATE TABLE IF NOT EXISTS survey_registration_invites (
            id INTEGER PRIMARY KEY, token_hash TEXT UNIQUE, created_by TEXT,
            created TEXT, expires TEXT, used_at TEXT, used_by INTEGER)');
    }

    protected function setUp(): void {
        DB::getInstance()->pdo()->exec('DELETE FROM survey_registration_invites');
        $this->configure(array());
    }

    protected function configure(array $registration) {
        $computed = new ReflectionProperty('Config', 'computed');
        $computed->setAccessible(true);
        $computed->setValue(null, array());
        $config = new ReflectionProperty('Config', 'config');
        $config->setAccessible(true);
        Config::initialize(array_merge((array) $config->getValue(null), array(
            'mail_guard' => array('form_secret' => self::SECRET),
            'registration' => array_merge(array('mode' => 'open', 'min_form_seconds' => 3), $registration),
        )));
    }

    /** A token minted $age seconds ago, signed with the configured secret. */
    protected function agedToken($age) {
        $payload = (time() - $age) . '.' . bin2hex(random_bytes(8));
        return $payload . '.' . hash_hmac('sha256', $payload, self::SECRET);
    }

    public function testFormTokenRoundTrip() {
        $token = RegistrationGuard::mintFormToken();
        $this->assertNotEmpty($token);
        $parsed = RegistrationGuard::parseFormToken($token);
        $this->assertTrue($parsed['ok']);
        $this->assertLessThanOrEqual(1, $parsed['age']);
    }

    public function testTamperedTokenIsRejected() {
        $token = RegistrationGuard::mintFormToken();
        $tampered = substr($token, 0, -4) . (substr($token, -4) === 'aaaa' ? 'bbbb' : 'aaaa');
        $this->assertFalse(RegistrationGuard::parseFormToken($tampered)['ok']);
        $this->assertFalse(RegistrationGuard::parseFormToken('')['ok']);
        $this->assertFalse(RegistrationGuard::parseFormToken('a.b')['ok']);
    }

    public function testHoneypotIsSilentlyRefused() {
        $check = RegistrationGuard::checkSubmission(new Request(array(
            RegistrationGuard::HONEYPOT_FIELD => 'http://spam.example',
            RegistrationGuard::TOKEN_FIELD => $this->agedToken(10),
        )));
        $this->assertFalse($check['ok']);
        $this->assertTrue($check['silent']);
        $this->assertEquals('refused_honeypot', $check['outcome']);
    }

    public function testTooFastSubmissionIsRefused() {
        $check = RegistrationGuard::checkSubmission(new Request(array(
            RegistrationGuard::TOKEN_FIELD => $this->agedToken(0),
        )));
        $this->assertFalse($check['ok']);
        $this->assertEquals('refused_too_fast', $check['outcome']);
        $this->assertFalse($check['silent']);
        $this->assertNotEmpty($check['message']);
    }

    public function testPlausiblyPacedSubmissionPasses() {
        $check = RegistrationGuard::checkSubmission(new Request(array(
            RegistrationGuard::TOKEN_FIELD => $this->agedToken(10),
        )));
        $this->assertTrue($check['ok']);
    }

    public function testMissingOrExpiredTokenIsRefused() {
        $check = RegistrationGuard::checkSubmission(new Request(array()));
        $this->assertFalse($check['ok']);
        $this->assertEquals('refused_form_token', $check['outcome']);

        $this->configure(array('form_token_ttl' => 60));
        $check = RegistrationGuard::checkSubmission(new Request(array(
            RegistrationGuard::TOKEN_FIELD => $this->agedToken(120),
        )));
        $this->assertFalse($check['ok']);
        $this->assertEquals('refused_form_token', $check['outcome']);
    }

    public function testGateModes() {
        $this->assertTrue(RegistrationGuard::gate('a@anywhere.org')['ok'], 'open is the default');

        $this->configure(array('mode' => 'closed'));
        $this->assertFalse(RegistrationGuard::gate('a@anywhere.org')['ok']);

        $this->configure(array('mode' => 'domains', 'allowed_domains' => array('Example.EDU')));
        $this->assertTrue(RegistrationGuard::gate('a@example.edu')['ok']);
        $this->assertTrue(RegistrationGuard::gate('a@psych.example.edu')['ok'], 'subdomains allowed');
        $this->assertFalse(RegistrationGuard::gate('a@evil.com')['ok']);
        $this->assertFalse(RegistrationGuard::gate('a@notexample.edu')['ok'], 'suffix must match on a label boundary');

        $this->configure(array('mode' => 'nonsense'));
        $this->assertFalse(RegistrationGuard::gate('a@anywhere.org')['ok'], 'unknown mode fails closed');
    }

    public function testInviteModeConsumesSingleUseTokens() {
        $this->configure(array('mode' => 'invite'));
        $this->assertFalse(RegistrationGuard::gate('a@x.org', null)['ok']);
        $this->assertFalse(RegistrationGuard::gate('a@x.org', 'wrong-token')['ok']);

        $token = 'test-invite-token';
        DB::getInstance()->insert('survey_registration_invites', array(
            'token_hash' => hash('sha256', $token), 'created_by' => 'test',
            'created' => date('Y-m-d H:i:s'), 'expires' => null,
        ));
        $this->assertTrue(RegistrationGuard::gate('a@x.org', $token)['ok']);
        $this->assertTrue(RegistrationGuard::consumeInvite($token, 42));
        $this->assertFalse(RegistrationGuard::gate('a@x.org', $token)['ok'], 'single use');
        $this->assertFalse(RegistrationGuard::consumeInvite($token, 43));

        DB::getInstance()->insert('survey_registration_invites', array(
            'token_hash' => hash('sha256', 'expired-token'), 'created_by' => 'test',
            'created' => date('Y-m-d H:i:s', time() - 90 * 86400),
            'expires' => date('Y-m-d H:i:s', time() - 3600),
        ));
        $this->assertFalse(RegistrationGuard::gate('a@x.org', 'expired-token')['ok']);
    }

    public function testChallengeDriverSelection() {
        $this->assertNull(RegistrationGuard::challengeDriver(), 'off by default');
        $this->configure(array('challenge' => 'altcha'));
        $this->assertInstanceOf('AltchaChallenge', RegistrationGuard::challengeDriver());
        $this->configure(array('challenge' => 'turnstile'));
        $this->assertInstanceOf('TurnstileChallenge', RegistrationGuard::challengeDriver());
        $this->configure(array('challenge' => 'bogus'));
        $this->assertNull(RegistrationGuard::challengeDriver());
    }

}
