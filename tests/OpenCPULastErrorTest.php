<?php

/**
 * X3 (documentation/agent_doc/LOG_STATE_AUDIT.md) — opencpu_last_error()
 * must describe the call it is asked about, through every call path that
 * can fail.
 *
 * Two staleness mechanisms are pinned here, driven through the real
 * wrappers against an unreachable OpenCPU (127.0.0.1:1 refuses connections
 * instantly, so no network or timeout is involved):
 *
 *  1. $GLOBALS['opencpu_local_error'] is cleared only by opencpu_evaluate(),
 *     so a pre-flight note from an earlier call in the same process (an
 *     empty Skip condition, say) is reported as a later knit's failure.
 *  2. A transport failure constructs no OpenCPU_Session, so the fallback in
 *     opencpu_last_error() reads the PREVIOUS session — whose hasError()
 *     consults the singleton's curl_info, which the failed request just
 *     overwrote.
 */
class OpenCPULastErrorTest extends \PHPUnit\Framework\TestCase
{
    private $savedInstances;

    protected function setUp(): void
    {
        $prop = new ReflectionProperty(OpenCPU::class, 'instances');
        $prop->setAccessible(true);
        $this->savedInstances = $prop->getValue();

        $unreachable = (new ReflectionClass(OpenCPU::class))->newInstanceWithoutConstructor();
        foreach (['localUrl', 'publicUrl'] as $urlProp) {
            $p = new ReflectionProperty(OpenCPU::class, $urlProp);
            $p->setAccessible(true);
            $p->setValue($unreachable, 'http://127.0.0.1:1');
        }
        $prop->setValue(null, ['opencpu_instance' => $unreachable]);

        $GLOBALS['opencpu_local_error'] = null;
        global $opencpu_session;
        $opencpu_session = null;
    }

    protected function tearDown(): void
    {
        $prop = new ReflectionProperty(OpenCPU::class, 'instances');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->savedInstances);

        $GLOBALS['opencpu_local_error'] = null;
        global $opencpu_session;
        $opencpu_session = null;
    }

    public function testStaleEmptyExpressionNoteIsNotReportedAsAKnitFailure(): void
    {
        // An earlier unit's empty condition leaves the pre-flight note …
        $this->assertNull(opencpu_evaluate('# comments only'));
        $this->assertStringContainsString('empty', (string) opencpu_last_error());

        // … then an unrelated knit fails in transport. The reported error
        // must describe THIS failure, not the earlier unit's note.
        $this->assertNull(opencpu_knit2html("```{r}\n1+1\n```"));
        $error = (string) opencpu_last_error();
        $this->assertStringNotContainsString('expression is empty', $error);
        $this->assertNotSame('', $error, 'a failed knit must leave a reportable error');
    }

    public function testTransportFailureIsNotAttributedToThePreviousSession(): void
    {
        // A successful earlier call left a session behind (every constructed
        // session registers itself as the global "last session").
        new OpenCPU_Session('http://127.0.0.1:1/ocpu/tmp/x0abc/', 'x0abc', 'OLD SUCCESS BODY', OpenCPU::getInstance());

        // The next knit dies in transport: no session is constructed.
        $this->assertNull(opencpu_knit_iframe("```{r}\nplot(1)\n```", []));
        $error = (string) opencpu_last_error();
        $this->assertStringNotContainsString('OLD SUCCESS BODY', $error);
        $this->assertNotSame('', $error, 'a transport failure must leave a reportable error');
    }

    public function testEvaluateTransportFailureLeavesAnAttributableError(): void
    {
        $this->assertNull(opencpu_evaluate('rnorm(1)', []));
        $error = (string) opencpu_last_error();
        $this->assertNotSame('', $error, 'an evaluate transport failure must be reportable');
        $this->assertStringNotContainsString('expression is empty', $error);
    }

    public function testEmptyExpressionNoteStillReportedForItsOwnCall(): void
    {
        $this->assertNull(opencpu_evaluate("# nothing\n  \n# here"));
        $this->assertStringContainsString('empty', (string) opencpu_last_error());
    }
}
