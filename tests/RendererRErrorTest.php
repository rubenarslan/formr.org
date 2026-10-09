<?php

/**
 * Every showif / dynamic value is evaluated inside its own tryCatch so one
 * broken author expression can't fail the whole OpenCPU batch (which used to
 * paint every item on the page invalid and show participants a raw R banner).
 *
 * The first version of that isolation discarded the condition —
 * `error = function(.e) NA` — which traded a loud failure for a silent one: a
 * `value` expression that threw resolved to NA and was then written to the
 * results as a NULL answer, with nothing in the logs and nothing on screen.
 *
 * These tests pin the current contract: the handler returns
 * conditionMessage(.e) into a sibling `fmr_err.` element, showifs keep their
 * NA fallback, and value expressions get NO fallback assignment at all so a
 * failed one is simply absent from the results.
 */
class RendererRErrorTest extends \PHPUnit\Framework\TestCase {

    private function renderer(): SpreadsheetRenderer {
        $renderer = (new ReflectionClass(SpreadsheetRenderer::class))->newInstanceWithoutConstructor();
        $study = new stdClass();
        $study->id = 1;
        $study->name = 'demo';
        $p = new ReflectionProperty(SpreadsheetRenderer::class, 'study');
        $p->setAccessible(true);
        $p->setValue($renderer, $study);
        return $renderer;
    }

    private function call(string $method, &$arg1, ...$rest) {
        $m = new ReflectionMethod(SpreadsheetRenderer::class, $method);
        $m->setAccessible(true);
        $args = array(&$arg1);
        foreach ($rest as $r) {
            $args[] = $r;
        }
        return $m->invokeArgs($this->renderer(), $args);
    }

    private function build(array $items): array {
        return $this->call('buildShowIfAndValueRCode', $items);
    }

    public function testShowIfKeepsItsNaFallbackAndCapturesTheMessage() {
        $items = array('q1' => new RErrorStubItem('q1', 'ran_group == 1'));
        $code = $this->build($items);

        $this->assertArrayHasKey('si.q1', $code);
        $r = $code['si.q1'];

        // NA fallback preserved: an erroring showif still lands on the ordinary
        // unresolved-visibility path rather than failing the batch
        $this->assertStringContainsString('si.q1 = NA', $r);
        // ...but the condition is reported, not discarded
        $this->assertStringContainsString('fmr_err.si.q1 = tryCatch(', $r);
        $this->assertStringContainsString('conditionMessage(.e)', $r);
        $this->assertStringNotContainsString('error = function(.e) NA', $r);
        $this->assertStringContainsString('ran_group == 1', $r);
    }

    public function testValueExpressionHasNoNaFallbackSoAFailureIsNeverPersisted() {
        $items = array('score' => new RErrorStubItem('score', '', 'sum(c(1, 2))'));
        $code = $this->build($items);

        $this->assertArrayHasKey('score', $code);
        $r = $code['score'];

        // The whole statement is the error capture; `score` exists only if the
        // expression completed, so array_key_exists('score', $results) is false
        // on failure and updateSurveyStudyRecord() writes nothing.
        $this->assertStringStartsWith('fmr_err.score = tryCatch(', $r);
        $this->assertStringContainsString('conditionMessage(.e)', $r);
        $this->assertStringNotContainsString('error = function(.e) NA', $r);
        $this->assertSame(0, preg_match('/^score\s*=\s*tryCatch/m', $r),
                'score must not be assigned the tryCatch result — that is the NULL-persisting regression');
        // it IS assigned inside the guarded body, i.e. only on success
        $this->assertSame(1, preg_match('/^score = \(function\(\)\{/m', $r));
        $this->assertStringContainsString('sum(c(1, 2))', $r);
    }

    public function testValueGatedOnAShowIfStillUsesTheDefiniteTruthyGate() {
        $items = array('score' => new RErrorStubItem('score', 'age > 18', 'age * 2'));
        $code = $this->build($items);

        $this->assertSame(array('si.score', 'score'), array_keys($code),
                'the showif must be emitted before the value that gates on it');
        $this->assertStringContainsString('isTRUE(as.logical((si.score)[1]))', $code['score']);
        $this->assertStringStartsWith('fmr_err.score = tryCatch(', $code['score']);
    }

    public function testNothingIsEmittedForItemsWithoutExpressions() {
        $items = array('plain' => new RErrorStubItem('plain'));
        $this->assertSame(array(), $this->build($items));
    }

    public function testErrorsAreClassifiedByKindAndSuccessMarkersAreIgnored() {
        // shape as it arrives from getJSONObject(): jsonlite boxes length-1
        // vectors, and a successful expression leaves an empty/NULL sibling
        $results = array(
            'si.q1' => array(null),
            'fmr_err.si.q1' => array("object 'ran_group' not found"),
            'si.q2' => array(true),
            'fmr_err.si.q2' => array(),      // succeeded
            'score' => array(4),
            'fmr_err.score' => array(),      // succeeded
            'fmr_err.broken' => array('boom in author code'),
            'fmr_err.blank' => array('   '), // whitespace-only is not an error
        );

        $m = new ReflectionMethod(SpreadsheetRenderer::class, 'collectRExpressionErrors');
        $m->setAccessible(true);
        $errors = $m->invoke($this->renderer(), $results);

        $this->assertSame(array('q1' => "object 'ran_group' not found"), $errors['showif']);
        $this->assertSame(array('broken' => 'boom in author code'), $errors['value']);
    }

    public function testCollectorToleratesANonArrayResult() {
        $m = new ReflectionMethod(SpreadsheetRenderer::class, 'collectRExpressionErrors');
        $m->setAccessible(true);
        $this->assertSame(array('showif' => array(), 'value' => array()),
                $m->invoke($this->renderer(), null));
    }

    public function testReportingIsANoOpWhenNothingFailed() {
        $m = new ReflectionMethod(SpreadsheetRenderer::class, 'reportRExpressionErrors');
        $m->setAccessible(true);
        // must not touch Site/Session/the log when there is nothing to say
        $this->assertNull($m->invoke($this->renderer(), array('showif' => array(), 'value' => array())));
    }

}

/**
 * The code builder only asks an item for its showif, whether it needs a dynamic
 * value, and what that value is — no need to drag a real Item (and its factory,
 * choice lists and DB) into a pure string-generation test.
 */
class RErrorStubItem {

    public $name;
    private $showif;
    private $value;

    public function __construct(string $name, string $showif = '', string $value = '') {
        $this->name = $name;
        $this->showif = $showif;
        $this->value = $value;
    }

    public function getShowIf() {
        return $this->showif;
    }

    public function needsDynamicValue() {
        return $this->value !== '';
    }

    public function getValue($study = null) {
        return $this->value;
    }

}
