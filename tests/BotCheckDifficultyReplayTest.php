<?php

use PHPUnit\Framework\TestCase;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\ChallengeParameters;
use AltchaOrg\Altcha\Algorithm\Argon2id;
use AltchaOrg\Altcha\Algorithm\Sha;
use AltchaOrg\Altcha\SolveChallengeOptions;

/**
 * bot_check difficulty is the server's decision, and a solved token is single-use.
 *
 * F2: the mint endpoint used to take `?difficulty=` from the query string and
 * sign whatever it was given, and verify() only checked the HMAC over what was
 * minted — so `?difficulty=1` downgraded a `bot_check 3` to a 1-byte prefix.
 * Now mint() derives the prefix from the item's type_options (or Config), a
 * caller-supplied number is ignored, and verify() re-derives the same floor
 * and refuses a genuinely signed but easier challenge.
 *
 * F3: verify() never consumed a token, so one solved PoW replayed for the
 * whole TTL (24h) — every bot_check the participant met again in that window
 * accepted it. A token that passes is now burned (RegistrationGuard's session
 * burn list, as the registration Altcha does).
 */
class BotCheckDifficultyReplayTest extends TestCase
{
    /** @var array */
    private $savedConfig;

    protected function setUp(): void
    {
        $config = new ReflectionProperty('Config', 'config');
        $config->setAccessible(true);
        $this->savedConfig = (array) $config->getValue(null);
        Session::delete(RegistrationGuard::BURN_SESSION_KEY);
        Config::initialize(array_merge($this->savedConfig, array(
            'bot_check_secret' => str_repeat('k', 48),
            'bot_check_argon_memory' => 256, // KiB, the floor memoryCost() accepts
            'bot_check_prefix_bytes' => 1,
        )));
    }

    protected function tearDown(): void
    {
        Session::delete(RegistrationGuard::BURN_SESSION_KEY);
        Config::initialize($this->savedConfig);
    }

    private function item($typeOptions = null)
    {
        $opts = array('type' => 'bot_check', 'name' => 'human');
        if ($typeOptions !== null) {
            $opts['type_options'] = $typeOptions;
        }
        return new BotCheck_Item($opts);
    }

    /** Mint for $item and do the proof-of-work the browser worker does. */
    private function solve($item = null)
    {
        $mint = BotCheckChallenge::mint($item);
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
        return base64_encode(json_encode(array('challenge' => $mint, 'solution' => $solution->toArray())));
    }

    // ---- F2: difficulty comes from the item / config, never the caller ------

    public function testExpectedPrefixBytesComesFromTheItemThenConfigAndClamps()
    {
        $this->assertSame(1, BotCheckChallenge::expectedPrefixBytes(null), 'config default');
        $this->assertSame(1, BotCheckChallenge::expectedPrefixBytes($this->item()), 'no type_options → config');
        $this->assertSame(3, BotCheckChallenge::expectedPrefixBytes($this->item('3')), '`bot_check 3`');
        $this->assertSame(2, BotCheckChallenge::expectedPrefixBytes($this->item('2')));
        $this->assertSame(3, BotCheckChallenge::expectedPrefixBytes($this->item('9')), 'clamped to MAX');
        $this->assertSame(1, BotCheckChallenge::expectedPrefixBytes($this->item('0')), 'clamped to default');
        $this->assertSame(1, BotCheckChallenge::expectedPrefixBytes($this->item('mylist')), 'a choice-list name is not a difficulty');
    }

    public function testMintIgnoresACallerSuppliedNumber()
    {
        // The old signature was mint($difficulty); a number must no longer be
        // honoured in either direction.
        $this->assertSame('00', BotCheckChallenge::mint(3)['parameters']['keyPrefix'], 'a bare 3 is not an item');
        $this->assertSame('00', BotCheckChallenge::mint('1')['parameters']['keyPrefix']);
        $this->assertSame('00', BotCheckChallenge::mint()['parameters']['keyPrefix']);
        $this->assertSame('000000', BotCheckChallenge::mint($this->item('3'))['parameters']['keyPrefix'], 'the item decides');
    }

    public function testATokenMintedAtALowerDifficultyIsRefusedForAHarderItem()
    {
        // Genuinely signed, genuinely solved — at the 1-byte default. That is
        // exactly what `?difficulty=1` used to obtain for a `bot_check 3`.
        $easy = $this->solve();

        $hard = $this->item('3');
        $this->assertFalse(BotCheckChallenge::verify($easy, $hard), 'easier genuine challenge must not satisfy a harder item');
        $this->assertNull($hard->validateInput($easy));
        $this->assertNotEmpty($hard->error);
        $this->assertNotSame('verified', $hard->reply);

        // ...and the refusal did not burn it: it is still good for the item it
        // was actually minted for.
        $this->assertTrue(BotCheckChallenge::verify($easy, $this->item()));
    }

    public function testRaisingTheConfiguredCostInvalidatesEasierInFlightTokens()
    {
        if (!BotCheckChallenge::argon2idAvailable()) {
            $this->markTestSkipped('memory cost only applies to Argon2id');
        }
        $token = $this->solve();
        Config::initialize(array_merge($this->savedConfig, array(
            'bot_check_secret' => str_repeat('k', 48),
            'bot_check_argon_memory' => 512,
            'bot_check_prefix_bytes' => 1,
        )));
        $this->assertFalse(BotCheckChallenge::verify($token), 'minted at 256 KiB, floor is now 512 KiB');
    }

    // ---- F3: a solved token is single-use ----------------------------------

    public function testASolvedTokenVerifiesOnceOnly()
    {
        $token = $this->solve();
        $this->assertTrue(BotCheckChallenge::verify($token));
        $this->assertFalse(BotCheckChallenge::verify($token), 'replay within the TTL must be refused');
        $this->assertFalse(BotCheckChallenge::verify($token));
    }

    public function testTheItemRefusesAReplayedToken()
    {
        $token = $this->solve();
        $first = $this->item();
        $this->assertSame('verified', $first->validateInput($token));
        $this->assertEmpty($first->error);

        // The same participant meets a bot_check again (a later page, the next
        // diary entry) and the client re-submits the token it already has.
        $second = $this->item();
        $this->assertNull($second->validateInput($token));
        $this->assertNotEmpty($second->error, 'a burned token must block the submit');
        $this->assertNotSame('verified', $second->reply);
    }

    public function testBurningOneTokenLeavesOtherChallengesUsable()
    {
        $a = $this->solve();
        $b = $this->solve();
        $this->assertTrue(BotCheckChallenge::verify($a));
        $this->assertTrue(BotCheckChallenge::verify($b), 'the burn is per challenge, not per participant');
        $this->assertFalse(BotCheckChallenge::verify($a));
        $this->assertFalse(BotCheckChallenge::verify($b));
    }

    public function testARefusedTokenIsNotBurned()
    {
        // Break the session binding on a valid token: verify() must refuse it
        // without consuming the underlying challenge for its rightful owner.
        $token = $this->solve();
        $arr = json_decode(base64_decode($token), true);
        $arr['challenge']['parameters']['data']['uc'] = 'someone-else';
        $rebound = base64_encode(json_encode($arr));
        $this->assertFalse(BotCheckChallenge::verify($rebound), 'signature no longer matches');
        $this->assertTrue(BotCheckChallenge::verify($token), 'the genuine token still works once');
    }
}
