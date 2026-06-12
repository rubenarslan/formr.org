<?php
use PHPUnit\Framework\TestCase;

/**
 * Tests for parsedown_text_safe() in Functions.php — the guard around
 * ParsedownExtra, whose block-HTML handling fatals with a PHP Error (not
 * an Exception) on malformed HTML such as a bare <head> tag.
 */
class ParsedownTextSafeTest extends TestCase
{
    /** A parser that always fails the way ParsedownExtra does: with an Error. */
    private function throwingParser()
    {
        return new class extends Parsedown {
            public function text($text)
            {
                throw new \Error('simulated parser crash');
            }
        };
    }

    private function freshSite()
    {
        $site = Site::getInstance();
        $site->alerts = [];
        $site->alert_types = ["alert-warning" => 0, "alert-success" => 0, "alert-info" => 0, "alert-danger" => 0];
        return $site;
    }

    public function testNormalMarkdownIsParsed()
    {
        $parsedown = new ParsedownExtra();
        $parsedown->setBreaksEnabled(true);
        $out = parsedown_text_safe($parsedown, 'some *emphasis* here');
        $this->assertStringContainsString('<em>emphasis</em>', $out);
    }

    public function testThrowingParserFallsBackToRawText()
    {
        $this->freshSite();
        $raw = "text that would crash the parser";
        $out = parsedown_text_safe($this->throwingParser(), $raw, 'the pause text');
        $this->assertSame($raw, $out);
    }

    public function testThrowingParserAlertsTheAuthorNamingTheSource()
    {
        $site = $this->freshSite();
        parsedown_text_safe($this->throwingParser(), 'x', 'the page body');
        $this->assertSame(1, $site->alert_types['alert-warning']);
        $this->assertStringContainsString('the page body', end($site->alerts));
        $this->assertStringContainsString('saved as-is', end($site->alerts));
    }

    public function testSourceNameIsHtmlEscapedInAlert()
    {
        $site = $this->freshSite();
        parsedown_text_safe($this->throwingParser(), 'x', "the label of item '<script>'");
        $this->assertStringContainsString('&lt;script&gt;', end($site->alerts));
        $this->assertStringNotContainsString("<script>", end($site->alerts));
    }

    /**
     * The real-world crash input: ParsedownExtra's processTagRoutine does an
     * unguarded DOMDocument node dance that throws a TypeError on a bare
     * <head> tag (observed through parsedown-extra 0.9.0 / parsedown 1.8.0).
     * We only assert it no longer fatals — if a future upstream release
     * fixes the parser, parsed output is fine too.
     */
    public function testKnownCrashInputDoesNotThrow()
    {
        $this->freshSite();
        $parsedown = new ParsedownExtra();
        $parsedown->setBreaksEnabled(true);
        $out = parsedown_text_safe($parsedown, "<head>\n", 'the page body');
        $this->assertIsString($out);
    }
}
