#!/usr/bin/php
<?php
// Backend smoke for the Altcha-backed bot_check (BotCheckChallenge + Argon2id).
// Run: docker exec formr_app php /var/www/formr/bin/bot_check_smoke.php
//
// Mints a real challenge, solves it with the altcha PHP lib, and asserts the
// solved token verifies — plus the negative cases (forged signature, wrong PoW
// solution, re-bound session, empty/garbage). In CLI there is no session, so
// BotCheckChallenge::subject() is '' for both mint and verify (consistent).
require_once dirname(__FILE__) . '/../setup.php';
if (php_sapi_name() !== 'cli') { die("CLI only\n"); }

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\ChallengeParameters;
use AltchaOrg\Altcha\Algorithm\Argon2id;
use AltchaOrg\Altcha\Algorithm\Sha;
use AltchaOrg\Altcha\SolveChallengeOptions;

$pass = 0; $fail = 0;
$check = function ($name, $got, $want) use (&$pass, &$fail) {
    if ($got === $want) { $pass++; echo "  ok   $name\n"; }
    else { $fail++; echo "  FAIL $name (got " . var_export($got, true) . ", want " . var_export($want, true) . ")\n"; }
};

$secret = BotCheckChallenge::secret();
echo "secret available: " . ($secret !== null ? "yes" : "NO") . "\n";
$useArgon = BotCheckChallenge::argon2idAvailable();
echo "algorithm: " . ($useArgon ? "Argon2id (memory-hard)" : "SHA-256 (fallback)") . "\n";
if ($secret === null) { fwrite(STDERR, "no secret → verify fails open; cannot test\n"); exit(2); }

$altcha = new Altcha($secret);
$alg = $useArgon ? new Argon2id() : new Sha();

// 1) mint a real, session-bound challenge ({parameters, signature})
$mint = BotCheckChallenge::mint();
echo "minted algorithm: " . ($mint['parameters']['algorithm'] ?? '?') . "\n";

// 2) solve it with the lib (the PoW the browser worker does)
$challenge = new Challenge(ChallengeParameters::fromArray($mint['parameters']), $mint['signature']);
$sol = $altcha->solveChallenge(new SolveChallengeOptions(challenge: $challenge, algorithm: $alg, timeout: 25.0));
$check('challenge solved', $sol !== null, true);
if ($sol === null) { echo "\n$pass passed, " . ($fail) . " failed (solver gave up)\n"; exit(1); }

$payload = fn ($c, $s) => base64_encode(json_encode(['challenge' => $c, 'solution' => $s]));

// 3) the solved token verifies — once. verify() burns the challenge on
//    success, so the same token must not pass a second time.
$check('valid solved token verifies', BotCheckChallenge::verify($payload($mint, $sol->toArray())), true);
$check('replayed token rejected (single use)', BotCheckChallenge::verify($payload($mint, $sol->toArray())), false);

// 4) negatives
$check('empty rejected', BotCheckChallenge::verify(''), false);
$check('garbage rejected', BotCheckChallenge::verify('not-base64-json'), false);

$forged = $mint; $forged['signature'] = str_repeat('0', strlen((string) $mint['signature']));
$check('forged signature rejected', BotCheckChallenge::verify($payload($forged, $sol->toArray())), false);

$badSol = $sol->toArray(); $badSol['counter'] = (int) $badSol['counter'] + 1;
$check('wrong PoW solution rejected', BotCheckChallenge::verify($payload($mint, $badSol)), false);

$rebind = $mint; $rebind['parameters']['data'] = ['uc' => 'someone-else'];
$check('re-bound session rejected (sig)', BotCheckChallenge::verify($payload($rebind, $sol->toArray())), false);

// 5) the item wraps verify() — a genuine token stores the compact marker, a
//    missing/garbage one sets ->error (which is what the save loop reads).
//    The token from step 3 is burned, so the item gets a fresh one.
$mint2 = BotCheckChallenge::mint();
$sol2 = $altcha->solveChallenge(new SolveChallengeOptions(
    challenge: new Challenge(ChallengeParameters::fromArray($mint2['parameters']), $mint2['signature']),
    algorithm: $alg, timeout: 25.0));
$item = new BotCheck_Item(['type' => 'bot_check', 'name' => 'human']);
$check('item accepts a solved token', $sol2 ? $item->validateInput($payload($mint2, $sol2->toArray())) : null, 'verified');
$item = new BotCheck_Item(['type' => 'bot_check', 'name' => 'human']);
$item->validateInput('');
$check('item blocks on an empty token', !empty($item->error), true);

// 6) FAIL CLOSED. Simulate the misconfiguration that used to make verify()
//    `return true` for everything: no configured secret and no readable crypto
//    key file. One unreadable key file silently disarmed every bot_check in the
//    install while the admin UI still showed it armed.
echo "\n-- fail-closed (simulated missing crypto key) --\n";
$configProp = new ReflectionProperty('Config', 'config');
$configProp->setAccessible(true);
$savedConfig = (array) $configProp->getValue(null);
Config::initialize(array_merge($savedConfig, [
    'bot_check_secret' => '',
    'encryption_key_file' => '/nonexistent/formr-crypto.key',
]));

$check('available() reports the outage', BotCheckChallenge::available(), false);
$check('mint() returns null (endpoint 503s)', BotCheckChallenge::mint(), null);
$check('empty token still refused', BotCheckChallenge::verify(''), false);
$check('previously-valid token refused', BotCheckChallenge::verify($payload($mint, $sol->toArray())), false);
$item = new BotCheck_Item(['type' => 'bot_check', 'name' => 'human']);
$check('item blocks the submit', $item->validateInput('anything'), null);
$check('item says "unavailable", not "try again"', strpos((string) $item->error, 'unavailable') !== false, true);

Config::initialize($savedConfig);
$check('config restored', BotCheckChallenge::available(), true);

// 4) bound to a unit session: a token minted for one is refused in another
//    (the single-use list is per PHP session; a bot dropping its cookie
//    must not replay one solved token in every diary entry).
BotCheckChallenge::bindTo(101);
$mintUs = BotCheckChallenge::mint();
$chUs = new Challenge(ChallengeParameters::fromArray($mintUs['parameters']), $mintUs['signature']);
$solUs = $altcha->solveChallenge(new SolveChallengeOptions(challenge: $chUs, algorithm: $alg, timeout: 25.0));
BotCheckChallenge::bindTo(202);
$check('a token minted for another unit session is refused', $solUs ? BotCheckChallenge::verify($payload($mintUs, $solUs->toArray())) : null, false);
BotCheckChallenge::bindTo(101);
$check('in its own unit session it verifies', $solUs ? BotCheckChallenge::verify($payload($mintUs, $solUs->toArray())) : null, true);
BotCheckChallenge::bindTo(null);


echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
