#!/usr/bin/php
<?php
/**
 * HTTP smoke for concurrent "Test Survey" previews (review 2026-07, item 17).
 *
 * The preview state used to live in ONE session slot (`test_study_data`), so
 * opening survey B's preview clobbered survey A's in-flight test — and tab
 * A's next request on a >15-min-old token then hit the 410 expired branch
 * (the continue-carve-out checks "is A's test live?", which the clobber made
 * false), silently discarding answers. The token already carries the study
 * identity, so state is now keyed per study and two tabs interleave freely.
 *
 * Drives the real vhost over HTTP with one cookie jar (two "tabs", one
 * browser):
 *  A. GET preview A (valid token)   -> 200, A's test is live
 *  B. GET preview B (valid token)   -> 200 (must NOT clobber A)
 *  C. GET preview A (EXPIRED token) -> 200 continue (red pre-fix: 410)
 *
 * Usage:  docker exec formr_app php bin/test_survey_test_two_tabs_smoke.php
 * Read-only fixtures: uses two small existing studies of one owner; the only
 * writes are the throwaway TEST_RUN unit sessions the previews create.
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();
$failures = 0;
function ok($cond, string $label): void {
    global $failures;
    echo $cond ? "  \e[32mOK\e[0m  {$label}\n" : "  \e[31mFAIL\e[0m {$label}\n";
    if (!$cond) { $GLOBALS['failures']++; }
}

// two small studies with one owner. They must contain at least one
// ANSWERABLE item: a submit-only fixture like just_submit legitimately
// completes on its first execute (nothing to answer), which deletes the
// preview state as a genuine finish and would mask the clobber under test.
$rows = $db->execute("SELECT id, name, user_id FROM survey_studies
    WHERE name IN ('how_you_doing','mood','enter_email','test_survey')
    ORDER BY FIELD(name,'how_you_doing','mood','enter_email','test_survey')");
$byUser = [];
foreach ($rows as $r) { $byUser[$r['user_id']][] = $r; }
$pair = null;
foreach ($byUser as $candidates) {
    if (count($candidates) >= 2) { $pair = array_slice($candidates, 0, 2); break; }
}
if (!$pair) { fwrite(STDERR, "NOFIXTURE: need two small studies with one owner\n"); exit(1); }
list($studyA, $studyB) = $pair;
$owner = (int) $studyA['user_id'];
echo "studies: A={$studyA['name']} B={$studyB['name']} owner={$owner}\n";

// The session cookie is Secure (path /survey-test/), so the smoke must speak
// real HTTPS — plain in-container HTTP would never replay it and every
// request would look like a fresh browser.
$adminHost = parse_url('https://' . trim(Config::get('admin_domain'), '/'), PHP_URL_HOST);
$jar = tempnam(sys_get_temp_dir(), 'zztabs');
$get = function (string $token) use ($adminHost, $jar) {
    $ch = curl_init("https://{$adminHost}/survey-test/{$token}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, (string) $body];
};

try {
    $tokenA        = SurveyTestController::mintToken($studyA['name'], $owner);
    $tokenB        = SurveyTestController::mintToken($studyB['name'], $owner);
    $tokenAExpired = SurveyTestController::mintToken($studyA['name'], $owner, -60);

    echo "== A: open preview A ==\n";
    list($code) = $get($tokenA);
    ok($code === 200, "preview A renders (HTTP {$code})");

    echo "\n== B: open preview B in a second tab (same browser session) ==\n";
    list($code) = $get($tokenB);
    ok($code === 200, "preview B renders (HTTP {$code})");

    echo "\n== C: tab A continues past token expiry (the carve-out) ==\n";
    list($code, $body) = $get($tokenAExpired);
    ok($code === 200, "A's in-flight test continues -> 200, not 410 (HTTP {$code})"
        . ($code === 410 ? " — B clobbered A's test state" : ""));
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    $failures++;
} finally {
    @unlink($jar);
}

echo "\n" . ($failures === 0 ? "\e[32mALL PASSED\e[0m\n" : "\e[31m{$failures} FAILURE(S)\e[0m\n");
exit($failures === 0 ? 0 : 1);
