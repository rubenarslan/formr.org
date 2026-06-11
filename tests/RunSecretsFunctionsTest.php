<?php
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the run-secrets / custom-R helper functions in
 * Functions.php and the RunSecret name gate. All functions under test
 * accept injected arrays (no run context, no DB), which is exactly why
 * those parameters exist.
 */
class RunSecretsFunctionsTest extends TestCase
{
    private function setFiddleUrl($url)
    {
        Config::initialize(['r_fiddle_url' => $url]);
        // Config::get() caches resolved values in a private static; reset
        // it so per-test overrides actually take effect.
        $ref = new ReflectionClass(Config::class);
        $cache = $ref->getProperty('computed');
        $cache->setAccessible(true);
        $cache->setValue(null, []);
    }

    // ---- opencpu_inject_secrets ----

    public function testOnlyReferencedSecretsAreInjected()
    {
        $secrets = ['api_key' => 'abc123', 'unused' => 'zzz999'];
        $code = 'httr::GET(url, key = .formr$secret_api_key)';
        $out = opencpu_inject_secrets($code, $secrets);
        $this->assertStringContainsString(".formr\$secret_api_key = 'abc123'", $out);
        $this->assertStringNotContainsString('zzz999', $out);
    }

    public function testNoSecretsYieldsEmptyString()
    {
        $this->assertSame('', opencpu_inject_secrets('any code', []));
        $this->assertSame('', opencpu_inject_secrets('no refs here', ['k' => 'v']));
    }

    public function testQuotesBackslashesAndNewlinesAreEscaped()
    {
        $secrets = ['tricky' => "it's a \\ multi\nline"];
        $out = opencpu_inject_secrets('.formr$secret_tricky', $secrets);
        // ' -> \' , \ -> \\ , newline -> the two-character \n sequence
        $this->assertStringContainsString("'it\\'s a \\\\ multi\\nline'", $out);
        // No raw newline may survive inside the quoted value (it could
        // terminate an Rmd chunk if the line starts with ```).
        $this->assertSame(1, substr_count($out, "\n"), 'only the trailing statement newline expected');
    }

    // ---- opencpu_redact_secrets ----

    public function testKnownSecretValuesAreRedacted()
    {
        $secrets = ['token' => 'supersecret42'];
        $out = opencpu_redact_secrets('error near supersecret42 in call', $secrets);
        $this->assertSame('error near [SECRET REDACTED] in call', $out);
    }

    public function testShortSecretsAreNotRedacted()
    {
        // A five-char secret must not blank out every occurrence of a
        // common substring; the 6-char minimum is documented behavior.
        $secrets = ['pin' => '12345'];
        $out = opencpu_redact_secrets('value 12345 appears', $secrets);
        $this->assertSame('value 12345 appears', $out);
    }

    public function testMultipleSecretsRedactedIndependently()
    {
        $secrets = ['a' => 'alpha-secret', 'b' => 'beta-secret'];
        $out = opencpu_redact_secrets('alpha-secret and beta-secret', $secrets);
        $this->assertSame('[SECRET REDACTED] and [SECRET REDACTED]', $out);
    }

    public function testEscapedFormsOfSecretsAreRedactedToo()
    {
        // Found in browser testing: the debugger shows the R source, where
        // the secret appears in its R-escaped form ('it\'s…'), not raw —
        // a literal match on the raw value sails right past it.
        $secrets = ['k' => 'it\'s&a"key<>123'];

        // exactly what opencpu_inject_secrets writes into the R source
        $r_source = opencpu_inject_secrets('.formr$secret_k', $secrets);
        $this->assertStringNotContainsString('[SECRET', $r_source, 'injection itself must carry the real value');
        $redacted = opencpu_redact_secrets($r_source, $secrets);
        $this->assertStringNotContainsString('key<>123', $redacted);
        $this->assertStringContainsString('[SECRET REDACTED]', $redacted);

        // JSON-encoded form, as in quoted request payloads in error output
        $json_text = 'request was ' . json_encode(['x' => 'it\'s&a"key<>123']);
        $redacted_json = opencpu_redact_secrets($json_text, $secrets);
        $this->assertStringNotContainsString('key<>123', $redacted_json);

        // Composed escaping — caught by the e2e leak sweep: the debugger's
        // Request panel shows the POST params, where the R-escaped
        // assignment is JSON-encoded AGAIN into the `text` param.
        $request_text = 'text=' . json_encode(opencpu_inject_secrets('.formr$secret_k', $secrets));
        $redacted_req = opencpu_redact_secrets($request_text, $secrets);
        $this->assertStringNotContainsString('key<>123', $redacted_req);
        $this->assertStringContainsString('[SECRET REDACTED]', $redacted_req);

        // URL-encoded request bodies
        $url_text = 'body: x=' . rawurlencode('it\'s&a"key<>123');
        $this->assertStringNotContainsString(rawurlencode('key<>123'), opencpu_redact_secrets($url_text, $secrets));

        // The exact form the debugger's Request panel shows: the injected
        // R assignment ('-escaped) is then addslashes'd by
        // OpenCPU_Request::__toString (so " -> \"). This is the leak the
        // e2e sweep caught that the addcslashes-only variant missed.
        $request_str = '...= ' . addslashes("'" . opencpu_inject_secrets('.formr$secret_k', $secrets));
        $redacted_request = opencpu_redact_secrets($request_str, $secrets);
        $this->assertStringNotContainsString('key<>123', $redacted_request);
        $this->assertStringContainsString('[SECRET REDACTED]', $redacted_request);
    }

    // ---- RunSecret::isValidName ----

    public function testSecretNameValidation()
    {
        $this->assertTrue(RunSecret::isValidName('api_key'));
        $this->assertTrue(RunSecret::isValidName('K2'));
        $this->assertFalse(RunSecret::isValidName(''));
        $this->assertFalse(RunSecret::isValidName('has space'));
        $this->assertFalse(RunSecret::isValidName("a = 1; q <- 2 #")); // R injection shape
        $this->assertFalse(RunSecret::isValidName('umläut'));
        $this->assertFalse(RunSecret::isValidName(str_repeat('a', 191))); // column budget
        $this->assertFalse(RunSecret::isValidName(null));
    }

    // ---- truncate_result_log ----

    public function testTruncateResultLogPassesShortAndNull()
    {
        $this->assertNull(truncate_result_log(null));
        $this->assertSame('short', truncate_result_log('short'));
    }

    public function testTruncateResultLogCapsAtMediumtextAndKeepsUtf8Valid()
    {
        $max = 16777215;
        // Multibyte char straddling the byte limit: mb_strcut must back
        // off rather than emit a broken UTF-8 sequence.
        $log = str_repeat('a', $max - 1) . '€' . str_repeat('b', 50);
        $out = truncate_result_log($log);
        $this->assertLessThanOrEqual($max, strlen($out));
        $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
        $this->assertSame($max - 1, strlen($out), 'cut backs off before the 3-byte €');
    }

    // ---- opencpu_fiddle_url / opencpu_fiddle_link ----

    public function testFiddleUrlEncodesCodeAsBase64UrlInFragment()
    {
        $this->setFiddleUrl('https://fiddle.rforms.org/');
        $code = "x <- 1\nmean(c(x, 2)) # comment with / slash + plus";
        $url = opencpu_fiddle_url($code, 'r');
        $this->assertStringStartsWith('https://fiddle.rforms.org/?lang=r#code=', $url);
        $fragment = substr($url, strpos($url, '#code=') + 6);
        // base64url alphabet only — no +, /, = that would need URL escaping
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $fragment);
        // round-trips back to the exact source
        $decoded = base64_decode(strtr($fragment, '-_', '+/'));
        $this->assertSame($code, $decoded);
    }

    public function testFiddleUrlRespectsLangAndDisabledConfig()
    {
        $this->setFiddleUrl('https://fiddle.example.org');
        $this->assertStringStartsWith('https://fiddle.example.org/?lang=rmd#code=', opencpu_fiddle_url('# Rmd', 'rmd'));

        $this->setFiddleUrl('');
        $this->assertSame('', opencpu_fiddle_url('x <- 1', 'r'));
        $this->assertSame('', opencpu_fiddle_link('x <- 1', 'r'));
    }

    public function testFiddleLinkIsHtmlEscapedAnchor()
    {
        $this->setFiddleUrl('https://fiddle.rforms.org/');
        $link = opencpu_fiddle_link('x <- 1', 'r');
        $this->assertStringContainsString('target="_blank" rel="noopener"', $link);
        $this->assertStringContainsString('Open in R Fiddle', $link);
    }
}
