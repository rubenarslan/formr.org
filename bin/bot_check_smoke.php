#!/usr/bin/php
<?php
// Smoke test for the local-only bot_check challenge (BotCheckChallenge).
// Run: docker exec formr_app php /var/www/formr/bin/bot_check_smoke.php
require_once dirname(__FILE__) . '/../setup.php';
if (php_sapi_name() !== 'cli') { die("CLI only\n"); }

function lzb($raw) {
    $bits = 0;
    for ($i = 0; $i < strlen($raw); $i++) {
        $b = ord($raw[$i]);
        if ($b === 0) { $bits += 8; continue; }
        $m = 0x80; while ($m && ($b & $m) === 0) { $bits++; $m >>= 1; }
        break;
    }
    return $bits;
}

$pass = 0; $fail = 0;
$check = function ($name, $got, $want) use (&$pass, &$fail) {
    if ($got === $want) { $pass++; echo "  ok   $name\n"; }
    else { $fail++; echo "  FAIL $name (got " . var_export($got, true) . ", want " . var_export($want, true) . ")\n"; }
};

echo "secret available: " . (BotCheckChallenge::secret() !== null ? "yes" : "NO") . "\n";
$c = BotCheckChallenge::mint();
echo "minted: " . json_encode($c) . "\n";

// Solve the PoW.
$nonce = 0;
while (lzb(hash('sha256', $c['salt'] . $nonce, true)) < $c['diff']) { $nonce++; }
echo "solved nonce=$nonce\n";

$tok = ['iat' => $c['iat'], 'salt' => $c['salt'], 'diff' => $c['diff'], 'sig' => $c['sig'], 'nonce' => (string) $nonce, 'el' => 500];

$check('valid token verifies', BotCheckChallenge::verify(json_encode($tok)), true);
$check('empty rejected', BotCheckChallenge::verify(''), false);
$check('garbage rejected', BotCheckChallenge::verify('not-json'), false);

$badNonce = $tok; $badNonce['nonce'] = '0';
$check('wrong nonce rejected (PoW)', BotCheckChallenge::verify(json_encode($badNonce)), false);

$lowDiff = $tok; $lowDiff['diff'] = 5;  // sig was for the real diff → mismatch
$check('lowered difficulty rejected (sig)', BotCheckChallenge::verify(json_encode($lowDiff)), false);

$badSig = $tok; $badSig['sig'] = str_repeat('0', 64);
$check('forged sig rejected', BotCheckChallenge::verify(json_encode($badSig)), false);

// A fast PoW solve must be ACCEPTED: `el` is client-reported (a real bot just
// claims a large value) and diff-15 legitimately finishes in tens of ms on
// modern hardware, so gating on it only blocked honest fast clients ("verified
// but can't proceed"). The signed PoW is the real proof; a forged nonce is
// still rejected above.
$fastSolve = $tok; $fastSolve['el'] = 10;
$check('fast solve accepted (el not gated)', BotCheckChallenge::verify(json_encode($fastSolve)), true);
$noEl = $tok; unset($noEl['el']);
$check('missing el accepted', BotCheckChallenge::verify(json_encode($noEl)), true);

$hp = $tok; $hp['hp'] = 'spam';
$check('honeypot filled rejected', BotCheckChallenge::verify(json_encode($hp)), false);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
