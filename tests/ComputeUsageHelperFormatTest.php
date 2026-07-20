<?php
use PHPUnit\Framework\TestCase;

/**
 * ComputeUsageHelper::formatDuration renders compute seconds for the
 * dashboards and limit emails. Inputs are ROUND(…, 1) floats, so the integer
 * modulo (`$seconds % 3600`) triggered PHP 8.1+ "Implicit conversion from
 * float to int loses precision" deprecations — twice per non-integer value,
 * logged on every render (review 2026-07, item 21c).
 */
class ComputeUsageHelperFormatTest extends TestCase
{
    public function testFractionalSecondsDoNotEmitDeprecation()
    {
        $errors = array();
        set_error_handler(function ($no, $str) use (&$errors) {
            $errors[] = $str;
            return true;
        }, E_DEPRECATED | E_WARNING | E_NOTICE);
        try {
            $out = ComputeUsageHelper::formatDuration(3661.5);
        } finally {
            restore_error_handler();
        }
        $this->assertSame(array(), $errors, 'formatDuration must not emit any deprecation/warning on a float input');
        $this->assertSame('1h 01m', $out);
    }

    public function testFormattingIsUnchanged()
    {
        $this->assertSame('0s', ComputeUsageHelper::formatDuration(0));
        $this->assertSame('2.4s', ComputeUsageHelper::formatDuration(2.4));
        $this->assertSame('4m 05s', ComputeUsageHelper::formatDuration(245.0));
        $this->assertSame('1h 23m', ComputeUsageHelper::formatDuration(3600 + 23 * 60 + 4.7));
    }
}
