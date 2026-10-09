<?php

/**
 * v1.8.0 — an empty R expression must not be sent to OpenCPU.
 *
 * opencpu_evaluate() wraps the author's code in a closure that opens with
 * `library(formr)`. When the code contributes no expression, the closure's
 * value is that call's own return value, and R's library() returns the
 * names of the attached packages — so evaluation "succeeded" with the
 * string "formr" instead of failing. Verified against the dev instance's
 * OpenCPU: empty, whitespace-only and comment-only expressions all came
 * back as 'formr'.
 *
 * The damage depends on the caller's coercion. strtotime('formr') is
 * false, so a Pause/Wait anchor fell through to check_failed; but
 * Branch/Skip applies (bool), and (bool) 'formr' is TRUE — an empty skip
 * condition jumped every single time, silently.
 *
 * These cases pin the classifier. False negatives are harmless (the code
 * just goes to OpenCPU as before); a false POSITIVE would refuse to
 * evaluate working code, so the "not empty" cases matter most.
 */
class OpenCpuEmptyCodeTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @dataProvider emptyCases
     */
    public function testRecognisesEmptyCode($code, $label): void
    {
        $this->assertTrue(opencpu_code_is_empty($code), $label . ' must count as empty');
    }

    public static function emptyCases(): array
    {
        return [
            ['', 'the empty string'],
            ['   ', 'whitespace'],
            ["\n\t \n", 'newlines and tabs'],
            ['# So kommt es nach Push-Nachrichten zum Fehler', 'a single comment (the reported case)'],
            ["# one\n# two\n", 'several comment lines'],
            ["\n  # indented comment  \n  ", 'a comment with surrounding whitespace'],
        ];
    }

    /**
     * @dataProvider realCodeCases
     */
    public function testDoesNotRefuseRealCode($code, $label): void
    {
        $this->assertFalse(opencpu_code_is_empty($code), $label . ' must still be evaluated');
    }

    public static function realCodeCases(): array
    {
        return [
            ['tail(survey_unit_sessions$created,1)', 'the default Wait anchor'],
            ['last(state_master$state)', 'a typical relative_to'],
            ['x <- 1 # trailing comment', 'code with a trailing comment'],
            ['"#"', 'a string that is just a hash'],
            ["paste0('#', 'tag')", 'a hash inside a string literal'],
            ["# leading comment\nTRUE", 'a comment followed by an expression'],
            ['FALSE', 'a bare FALSE — falsy, but real code'],
            ['0', 'a bare zero'],
        ];
    }
}
