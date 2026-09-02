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
        // DBTest swaps the singleton for a bare :memory: connection, so the
        // bootstrap tables may be gone by the time this class runs
        DB::getInstance()->pdo()->exec('CREATE TABLE IF NOT EXISTS survey_users (
            id INTEGER PRIMARY KEY, email TEXT, password TEXT, admin INTEGER, user_code TEXT,
            referrer_code TEXT, first_name TEXT, last_name TEXT, affiliation TEXT,
            created TEXT, email_verified INTEGER)');
    }

    protected function setUp(): void {
        DB::getInstance()->pdo()->exec('DELETE FROM survey_registration_invites');
        $_SESSION = array(); // the burn list lives in the session
        $this->configure(array());
    }

    protected function configure(array $registration, array $extra = array()) {
        $computed = new ReflectionProperty('Config', 'computed');
        $computed->setAccessible(true);
        $computed->setValue(null, array());
        $config = new ReflectionProperty('Config', 'config');
        $config->setAccessible(true);
        Config::initialize(array_merge((array) $config->getValue(null), $extra, array(
            'mail_guard' => array('form_secret' => self::SECRET),
            'registration' => array_merge(array('mode' => 'open', 'min_form_seconds' => 3), $registration),
        )));
    }

    /** A token minted $age seconds ago for session $sid ('' = this CLI process), signed with the configured secret. */
    protected function agedToken($age, $sid = '') {
        $payload = (time() - $age) . '.' . bin2hex(random_bytes(8));
        return $payload . '.' . hash_hmac('sha256', $payload . '|' . $sid, self::SECRET);
    }

    protected function insertInvite($token, $expires = null) {
        DB::getInstance()->insert('survey_registration_invites', array(
            'token_hash' => hash('sha256', $token), 'created_by' => 'test',
            'created' => date('Y-m-d H:i:s'), 'expires' => $expires,
        ));
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

    public function testGateAllowsByTld() {
        $this->configure(array('mode' => 'domains', 'allowed_domains' => array(), 'allowed_tlds' => array('edu', 'ac.uk')));
        $this->assertTrue(RegistrationGuard::gate('a@uni-example.edu')['ok']);
        $this->assertTrue(RegistrationGuard::gate('a@dept.school.ac.uk')['ok'], 'multi-label TLD');
        $this->assertFalse(RegistrationGuard::gate('a@example.com')['ok']);
        $this->assertFalse(RegistrationGuard::gate('a@notedu.com')['ok'], 'TLD must be the real suffix, not a substring');

        // allowed_domains and allowed_tlds combine — either grants
        $this->configure(array('mode' => 'domains', 'allowed_domains' => array('example.org'), 'allowed_tlds' => array('edu')));
        $this->assertTrue(RegistrationGuard::gate('a@example.org')['ok']);
        $this->assertTrue(RegistrationGuard::gate('a@x.edu')['ok']);
        $this->assertFalse(RegistrationGuard::gate('a@example.com')['ok']);
    }

    public function testSignupIsTrusted() {
        // referral-code path (open / domains modes)
        $this->configure(array('mode' => 'open'), array('referrer_codes' => array('goodcode')));
        $this->assertTrue(RegistrationGuard::signupIsTrusted('goodcode'));
        $this->assertFalse(RegistrationGuard::signupIsTrusted('badcode'));
        $this->assertFalse(RegistrationGuard::signupIsTrusted(''));

        // a usable single-use invite is admin-issued, so trusted in ANY mode
        $token = 'trusted-invite-token';
        DB::getInstance()->insert('survey_registration_invites', array(
            'token_hash' => hash('sha256', $token), 'created_by' => 'test',
            'created' => date('Y-m-d H:i:s'), 'expires' => null));
        $this->configure(array('mode' => 'invite'), array('referrer_codes' => array()));
        $this->assertTrue(RegistrationGuard::signupIsTrusted($token));
        $this->assertFalse(RegistrationGuard::signupIsTrusted('nope'));
        $this->configure(array('mode' => 'open'), array('referrer_codes' => array()));
        $this->assertTrue(RegistrationGuard::signupIsTrusted($token), 'admin-issued invites are trusted in any mode');
    }

    public function testInviteOverridesDomainGate() {
        $this->configure(array('mode' => 'domains', 'allowed_domains' => array('example.org'), 'allowed_tlds' => array()));
        $token = 'domain-exception-token';
        DB::getInstance()->insert('survey_registration_invites', array(
            'token_hash' => hash('sha256', $token), 'created_by' => 'test',
            'created' => date('Y-m-d H:i:s'), 'expires' => null));
        $this->assertFalse(RegistrationGuard::gate('a@blocked.com', null)['ok'], 'blocked without a token');
        $this->assertFalse(RegistrationGuard::gate('a@blocked.com', 'wrong')['ok'], 'a wrong token grants nothing');
        $this->assertTrue(RegistrationGuard::gate('a@blocked.com', $token)['ok'], 'a valid invite is the per-person exception');
        // burning the invite closes the exception again
        $this->assertTrue(RegistrationGuard::consumeInvite($token, 7));
        $this->assertFalse(RegistrationGuard::gate('a@blocked.com', $token)['ok'], 'single use');
    }

    public function testIssueListRevokeInvites() {
        $this->configure(array('mode' => 'open'), array('referrer_codes' => array()));
        $tokens = RegistrationGuard::issueInvites(2, 30, 'admin:test@example.org');
        $this->assertCount(2, $tokens);
        $this->assertTrue(RegistrationGuard::signupIsTrusted($tokens[0]), 'freshly issued invite is usable');

        $rows = RegistrationGuard::listInvites(); // newest first: rows[0] is tokens[1]
        $this->assertCount(2, $rows);
        $this->assertEquals('admin:test@example.org', $rows[0]['created_by']);

        $this->assertTrue(RegistrationGuard::revokeInviteById($rows[0]['id']));
        $this->assertCount(1, RegistrationGuard::listInvites());
        $this->assertFalse(RegistrationGuard::signupIsTrusted($tokens[1]), 'revoked invite is dead');

        // a used invite cannot be revoked away (audit trail)
        $this->assertTrue(RegistrationGuard::consumeInvite($tokens[0], 9));
        $used = RegistrationGuard::listInvites();
        $this->assertNotNull($used[0]['used_at']);
        $this->assertFalse(RegistrationGuard::revokeInviteById($used[0]['id']));
        $this->assertCount(1, RegistrationGuard::listInvites(), 'used invite kept for the audit trail');
    }

    public function testHoneypotZeroIsStillAHoneypotHit() {
        // '0' is falsy in PHP; a bot that fills the hidden field with "0"
        // must not slip through an empty() check.
        $check = RegistrationGuard::checkSubmission(new Request(array(
            RegistrationGuard::HONEYPOT_FIELD => '0',
            RegistrationGuard::TOKEN_FIELD => $this->agedToken(10),
        )));
        $this->assertFalse($check['ok']);
        $this->assertEquals('refused_honeypot', $check['outcome']);
    }

    public function testFormTokenIsSingleUse() {
        $token = $this->agedToken(10);
        $request = new Request(array(RegistrationGuard::TOKEN_FIELD => $token));
        $this->assertTrue(RegistrationGuard::checkSubmission($request)['ok']);

        $replay = RegistrationGuard::checkSubmission($request);
        $this->assertFalse($replay['ok'], 'a passed token is burned for the rest of its TTL');
        $this->assertEquals('refused_form_token', $replay['outcome']);

        // a different token in the same session is unaffected
        $this->assertTrue(RegistrationGuard::checkSubmission(new Request(array(
            RegistrationGuard::TOKEN_FIELD => $this->agedToken(10),
        )))['ok']);
    }

    public function testBurnListExpiresAndIsBounded() {
        RegistrationGuard::burn('x:expired', time() - 1);
        RegistrationGuard::burn('x:live', time() + 60);
        $this->assertFalse(RegistrationGuard::isBurned('x:expired'), 'expired burns are forgotten');
        $this->assertTrue(RegistrationGuard::isBurned('x:live'));
        $this->assertArrayNotHasKey('x:expired', Session::get(RegistrationGuard::BURN_SESSION_KEY), 'expired entries are pruned on write');
    }

    public function testFormTokenIsBoundToTheSession() {
        // signed for another browser's session id: same secret, still refused
        $check = RegistrationGuard::checkSubmission(new Request(array(
            RegistrationGuard::TOKEN_FIELD => $this->agedToken(10, 'someone-elses-session'),
        )));
        $this->assertFalse($check['ok']);
        $this->assertEquals('refused_form_token', $check['outcome']);
    }

    public function testClaimBindReleaseInvite() {
        $this->configure(array('mode' => 'invite'));
        $token = 'claimable-token';
        $this->insertInvite($token);

        $this->assertTrue(RegistrationGuard::claimInvite($token));
        $this->assertFalse(RegistrationGuard::claimInvite($token), 'a claimed invite cannot be claimed twice');
        $this->assertFalse(RegistrationGuard::gate('a@x.org', $token)['ok'], 'in-flight invite is not usable by a second signup');

        // signup failed after the claim: the invite goes back into the pool
        $this->assertTrue(RegistrationGuard::releaseInvite($token));
        $this->assertTrue(RegistrationGuard::gate('a@x.org', $token)['ok']);
        $this->assertFalse(RegistrationGuard::releaseInvite($token), 'nothing in flight to release');

        // signup succeeded: bind to the account, then it stays consumed
        $this->assertTrue(RegistrationGuard::claimInvite($token));
        $this->assertTrue(RegistrationGuard::bindInvite($token, 42));
        $this->assertFalse(RegistrationGuard::bindInvite($token, 43), 'already bound');
        $this->assertFalse(RegistrationGuard::releaseInvite($token), 'a bound invite is never released');
        $rows = RegistrationGuard::listInvites();
        $this->assertEquals(42, (int) $rows[0]['used_by']);
        $this->assertNotNull($rows[0]['used_at']);

        $this->assertFalse(RegistrationGuard::claimInvite('never-issued'));
        $this->insertInvite('expired-claim', date('Y-m-d H:i:s', time() - 60));
        $this->assertFalse(RegistrationGuard::claimInvite('expired-claim'));
    }

    public function testMissingInviteTableFailsClosed() {
        $pdo = DB::getInstance()->pdo();
        $pdo->exec('ALTER TABLE survey_registration_invites RENAME TO survey_registration_invites_gone');
        try {
            $this->configure(array('mode' => 'invite'));
            $this->assertFalse(RegistrationGuard::gate('a@x.org', 'any-token')['ok'], 'no table: no invite is usable');
            $this->assertFalse(RegistrationGuard::signupIsTrusted('any-token'));
            $this->assertFalse(RegistrationGuard::claimInvite('any-token'));
            $this->assertFalse(RegistrationGuard::accountIsTrusted(1, 'any-token'));
            $this->assertFalse(RegistrationGuard::releaseInvite('any-token'));
        } finally {
            $pdo->exec('ALTER TABLE survey_registration_invites_gone RENAME TO survey_registration_invites');
        }
    }

    public function testAccountIsTrusted() {
        $this->configure(array('mode' => 'open'), array('referrer_codes' => array('goodcode')));
        $this->assertTrue(RegistrationGuard::accountIsTrusted(5, 'goodcode'), 'referral code recorded on the account');
        $this->assertFalse(RegistrationGuard::accountIsTrusted(5, 'badcode'));
        $this->assertFalse(RegistrationGuard::accountIsTrusted(5, ''));

        // an invite is trusted only for the account it was bound to
        $token = 'bound-token';
        $this->insertInvite($token);
        $this->assertTrue(RegistrationGuard::consumeInvite($token, 5));
        $this->assertTrue(RegistrationGuard::accountIsTrusted(5, ''), 'the binding is on the account, no code needed');
        $this->assertFalse(RegistrationGuard::accountIsTrusted(6, $token), 'someone else\'s invite');
        $this->assertFalse(RegistrationGuard::accountIsTrusted(0, 'badcode'));
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

    public function testSecondSignupFromTheSameSessionGetsAFreshUserCode() {
        $db = DB::getInstance();
        $db->pdo()->exec("DELETE FROM survey_users WHERE email LIKE 'code-%@example.com'");
        $visitor = new User(null, 'visitor-code-1234');
        $this->assertSame('visitor-code-1234', $visitor->signupUserCode(), 'a free code links the visitor\'s surveys to the new account');

        $db->insert('survey_users', array('email' => 'code-first@example.com', 'user_code' => 'visitor-code-1234', 'created' => date('Y-m-d H:i:s')));
        $fresh = $visitor->signupUserCode();
        $this->assertNotSame('visitor-code-1234', $fresh, 'the code already belongs to an account');
        $this->assertSame(strlen(crypto_token(48)), strlen($fresh));

        $arrival = new User(null, 'arrival-code-5678');
        $this->assertSame('arrival-code-5678', $arrival->signupUserCode());
        $arrival->user_code = null;
        $this->assertNotEmpty($arrival->signupUserCode(), 'no code at all still gets one');
        $db->pdo()->exec("DELETE FROM survey_users WHERE email LIKE 'code-%@example.com'");
    }

}
