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

// Freshness: a generous, session-bound TTL — slowness isn't a bot signal, and
// in form_v2 the challenge is minted at full-form render. An hour-old token
// (rejected under the old 30-min TTL) must now pass; a wildly stale one is
// still rejected as a hygiene bound.
$sign = new ReflectionMethod('BotCheckChallenge', 'sign');
$sign->setAccessible(true);
$forge = function ($iat) use ($c, $nonce, $sign) {
    return json_encode(['iat' => $iat, 'salt' => $c['salt'], 'diff' => $c['diff'],
        'sig' => $sign->invoke(null, $iat, $c['salt'], $c['diff']), 'nonce' => (string) $nonce, 'el' => 50]);
};
$check('hour-old token accepted (TTL not a gate)', BotCheckChallenge::verify($forge(time() - 3600)), true);
$check('very stale token rejected (TTL hygiene)', BotCheckChallenge::verify($forge(time() - 90000)), false);
$check('future-iat token rejected (clock skew)', BotCheckChallenge::verify($forge(time() + 600)), false);

// Customisable copy (consent / affirmation gate): choices relabel the box +
// confirmation, and must NOT trip the "this type doesn't have choices" error.
$consent = new BotCheck_Item([
    'id' => 9001, 'name' => 'consent_demo', 'label' => 'Please confirm', 'optional' => 1,
    'choices' => [1 => 'I confirm I am responding myself.', 2 => 'Confirmed, thanks.', 3 => 'Recording…'],
]);
$verr = $consent->validate()['val_errors'];
$choiceErr = false;
foreach ($verr as $e) { if (stripos($e, "doesn't have choices") !== false) { $choiceErr = true; } }
$check('validate() allows optional choices on bot_check', $choiceErr, false);
$rm = new ReflectionMethod('BotCheck_Item', 'render_input');
$rm->setAccessible(true);
$chtml = $rm->invoke($consent);
$check('box label uses choice1', strpos($chtml, 'I confirm I am responding myself.') !== false, true);
$check('confirmation text uses choice2', strpos($chtml, 'data-verified-text="Confirmed, thanks."') !== false, true);
$check('progress text uses choice3', strpos($chtml, 'data-verifying-text=') !== false, true);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
