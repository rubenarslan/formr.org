<?php

use PHPUnit\Framework\TestCase;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\ChallengeParameters;
use AltchaOrg\Altcha\Algorithm\Argon2id;
use AltchaOrg\Altcha\Algorithm\Sha;
use AltchaOrg\Altcha\SolveChallengeOptions;

/**
 * bot_check must fail CLOSED.
 *
 * BotCheckChallenge::verify() used to `return true` whenever the server had no
 * signing secret ("can't sign → can't challenge; don't lock people out"). That
 * branch is reachable with an empty token, so one unreadable crypto key file
 * silently turned every bot_check in the install into a no-op that still looked
 * armed in the admin UI — a security control whose failure mode was "accept
 * everything, tell nobody". It now refuses, logs, and the item surfaces a
 * distinct "temporarily unavailable" message so the misconfiguration is visible
 * instead of permanent and silent.
 */
class BotCheckFailClosedTest extends TestCase
{
    /** @var array */
    private $savedConfig;

    protected function setUp(): void
    {
        $config = new ReflectionProperty('Config', 'config');
        $config->setAccessible(true);
        $this->savedConfig = (array) $config->getValue(null);
    }

    protected function tearDown(): void
    {
        Config::initialize($this->savedConfig);
    }

    /** Merge overrides into the live config (Config::initialize replaces wholesale). */
    private function reconfigure(array $overrides)
    {
        Config::initialize(array_merge($this->savedConfig, $overrides));
    }

    /** No configured secret AND no readable key file => secret() is null. */
    private function breakTheSecret()
    {
        $this->reconfigure(array(
            'bot_check_secret' => '',
            'encryption_key_file' => '/nonexistent/formr-crypto.key',
        ));
    }

    /** A known-good secret, and a cheap PoW so solving one is unit-test fast. */
    private function workingSecret()
    {
        $this->reconfigure(array(
            'bot_check_secret' => str_repeat('k', 48),
            'bot_check_argon_memory' => 256, // KiB, the floor memoryCost() accepts
            'bot_check_prefix_bytes' => 1,
        ));
    }

    private function item()
    {
        return new BotCheck_Item(array('type' => 'bot_check', 'name' => 'human'));
    }

    // ---- the fail-closed regression -------------------------------------

    public function testAvailabilityTracksTheSecret()
    {
        $this->workingSecret();
        $this->assertTrue(BotCheckChallenge::available());
        $this->breakTheSecret();
        $this->assertFalse(BotCheckChallenge::available());
    }

    public function testUnsignableServerRefusesEveryToken()
    {
        $this->breakTheSecret();
        $this->assertNull(BotCheckChallenge::mint(), 'nothing to sign with');
        foreach (array('', null, 'anything', 'eyJhIjoxfQ==', array('challenge' => array(), 'solution' => array())) as $token) {
            $this->assertFalse(BotCheckChallenge::verify($token), var_export($token, true) . ' must not be accepted');
        }
    }

    public function testItemBlocksWithADistinctMessageWhenTheServerCannotVerify()
    {
        $this->breakTheSecret();
        $item = $this->item();
        $this->assertNull($item->validateInput('whatever-the-client-sent'));
        $this->assertNotEmpty($item->error, 'a failed check must set ->error; the save loop reads that, not the return value');
        $this->assertStringContainsString('unavailable', $item->error);
        $this->assertNotSame('verified', $item->reply);
    }

    // ---- ordinary refusals, with a working secret ------------------------

    /**
     * @dataProvider unusableReplies
     */
    public function testItemRefusesUnusableReplies($reply)
    {
        $this->workingSecret();
        $item = $this->item();
        $this->assertNull($item->validateInput($reply));
        $this->assertNotEmpty($item->error);
        $this->assertNotSame('verified', $item->reply);
    }

    public static function unusableReplies()
    {
        return array(
            'missing' => array(null),
            'empty' => array(''),
            'whitespace' => array("  \n "),
            'garbage' => array('not-base64-json'),
            'unsigned json' => array(base64_encode('{"challenge":{"parameters":{}},"solution":{}}')),
            'array smuggling' => array(array('challenge' => array('parameters' => array('algorithm' => 'SHA-256')), 'solution' => array())),
            'boolean' => array(true),
        );
    }

    public function testForgedSignatureIsRefused()
    {
        $this->workingSecret();
        $solved = $this->solveAFreshChallenge();
        $forged = $solved['challenge'];
        $forged['signature'] = str_repeat('0', strlen((string) $solved['challenge']['signature']));
        $token = base64_encode(json_encode(array('challenge' => $forged, 'solution' => $solved['solution'])));

        $item = $this->item();
        $this->assertNull($item->validateInput($token));
        $this->assertNotEmpty($item->error);
    }

    // ---- `optional` may be skipped, never forged ---------------------------

    private function optionalItem()
    {
        return new BotCheck_Item(array('type' => 'bot_check', 'name' => 'human', 'optional' => 1));
    }

    /**
     * @dataProvider skippedReplies
     */
    public function testOptionalItemAcceptsAnAbsentToken($reply)
    {
        $this->workingSecret();
        $item = $this->optionalItem();
        $this->assertNull($item->validateInput($reply));
        $this->assertEmpty($item->error, 'an optional bot_check left alone must not block the submit');
        $this->assertNull($item->reply);
    }

    public static function skippedReplies()
    {
        return array(
            'missing' => array(null),
            'empty' => array(''),
            'whitespace' => array("  \n "),
        );
    }

    /**
     * @dataProvider unusableTokens
     */
    public function testOptionalItemStillRefusesAnUnusableToken($reply)
    {
        $this->workingSecret();
        $item = $this->optionalItem();
        $this->assertNull($item->validateInput($reply));
        $this->assertNotEmpty($item->error, 'optional means skippable, not forgeable');
        $this->assertNotSame('verified', $item->reply);
    }

    public static function unusableTokens()
    {
        $all = self::unusableReplies();
        return array_diff_key($all, self::skippedReplies());
    }

    public function testOptionalItemBlocksWhenTheServerCannotVerifyASubmittedToken()
    {
        $this->breakTheSecret();
        $item = $this->optionalItem();
        $this->assertNull($item->validateInput('whatever-the-client-sent'));
        $this->assertStringContainsString('unavailable', (string) $item->error);
        // ...but skipping it still works even then: nothing needs verifying
        $item = $this->optionalItem();
        $this->assertNull($item->validateInput(''));
        $this->assertEmpty($item->error);
    }

    // ---- and the accept path still accepts -------------------------------

    public function testGenuineSolvedTokenIsAccepted()
    {
        $this->workingSecret();
        $solved = $this->solveAFreshChallenge();
        $token = base64_encode(json_encode($solved));

        $item = $this->item();
        $this->assertSame('verified', $item->validateInput($token));
        $this->assertSame('verified', $item->reply, 'the compact marker is stored, not the base64 blob');
        $this->assertEmpty($item->error);

        // an optional item that WAS solved records the verification too
        // (tokens are single-use / burned on verify, so solve a fresh one)
        $solved2 = $this->solveAFreshChallenge();
        $token2 = base64_encode(json_encode($solved2));
        $item = $this->optionalItem();
        $this->assertSame('verified', $item->validateInput($token2));
        $this->assertEmpty($item->error);
    }

    /** Mint a real challenge and do the proof-of-work the browser worker does. */
    private function solveAFreshChallenge()
    {
        $mint = BotCheckChallenge::mint();
        $this->assertIsArray($mint);
        $alg = BotCheckChallenge::argon2idAvailable() ? new Argon2id() : new Sha();
        $altcha = new Altcha(BotCheckChallenge::secret());
        $challenge = new Challenge(ChallengeParameters::fromArray($mint['parameters']), $mint['signature']);
        $solution = $altcha->solveChallenge(new SolveChallengeOptions(
            challenge: $challenge, algorithm: $alg, timeout: 20.0
        ));
        if ($solution === null) {
            $this->markTestSkipped('proof-of-work solver gave up within the timeout');
        }
        return array('challenge' => $mint, 'solution' => $solution->toArray());
    }
}
