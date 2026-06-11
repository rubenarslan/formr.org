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

// 3) the solved token verifies
$check('valid solved token verifies', BotCheckChallenge::verify($payload($mint, $sol->toArray())), true);

// 4) negatives
$check('empty rejected', BotCheckChallenge::verify(''), false);
$check('garbage rejected', BotCheckChallenge::verify('not-base64-json'), false);

$forged = $mint; $forged['signature'] = str_repeat('0', strlen((string) $mint['signature']));
$check('forged signature rejected', BotCheckChallenge::verify($payload($forged, $sol->toArray())), false);

$badSol = $sol->toArray(); $badSol['counter'] = (int) $badSol['counter'] + 1;
$check('wrong PoW solution rejected', BotCheckChallenge::verify($payload($mint, $badSol)), false);

$rebind = $mint; $rebind['parameters']['data'] = ['uc' => 'someone-else'];
$check('re-bound session rejected (sig)', BotCheckChallenge::verify($payload($rebind, $sol->toArray())), false);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
