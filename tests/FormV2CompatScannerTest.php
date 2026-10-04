<?php

use PHPUnit\Framework\TestCase;

/**
 * A form_v2 showif runs only in the browser, which knows the form's own items
 * and nothing else. FormV2CompatScanner::namesNotOnForm() finds the names a
 * showif reads that the browser can never resolve (a run variable, another
 * survey's item), so the scan can flag them before the study is upgraded.
 */
class FormV2CompatScannerTest extends TestCase
{
    private function unknown($expr, array $items = array('q1', 'q2', 'animals'))
    {
        return FormV2CompatScanner::namesNotOnForm($expr, array_fill_keys($items, true));
    }

    public function testFormItemsAndHelpersAreKnown()
    {
        $this->assertSame(array(), $this->unknown('q1 == 1 && answered(q2)'));
        $this->assertSame(array(), $this->unknown('!contains(animals, "cat") || isNA(q2)'));
        $this->assertSame(array(), $this->unknown('q1 === true || q2 !== null'));
    }

    public function testNamesOutsideTheFormAreReported()
    {
        $this->assertSame(array('ran_group'), $this->unknown('ran_group == 1'));
        $this->assertSame(array('other_survey_item'), $this->unknown('q1 > 2 && other_survey_item == "x"'));
    }

    public function testStringsCommentsPropertiesAndCallsAreNotNames()
    {
        $this->assertSame(array(), $this->unknown('q1 == "ran_group" || q2 == \'x y\''));
        $this->assertSame(array(), $this->unknown('/*sjv1*/q1 == 2 // ran_group'));
        $this->assertSame(array(), $this->unknown('animals.length > 2 && Math.max(q1, 3) > 1'));
        // an unknown *function* is detectRTokens()' business, not a name
        $this->assertSame(array(), $this->unknown('ifelse(q1, 1, 2)'));
    }

    /**
     * A `//` inside a string is text, not a comment: the names after it on
     * the same line are still read. namesIn() also decides which no-input
     * values the v2 state block ships (FormSubmissionService::showifNames),
     * so a URL before `ran_group` used to withhold ran_group from the
     * browser and keep the item hidden for everyone.
     */
    public function testAStringHoldingSlashesHidesNothingAfterIt()
    {
        $expr = 'q1 == "https://partner.example.org" || ran_group == 2';
        $this->assertSame(array('q1', 'ran_group'), FormV2CompatScanner::namesIn($expr));
        $this->assertSame(array('ran_group'), $this->unknown($expr));
        $this->assertContains('TRUE/FALSE: use true/false', FormV2CompatScanner::detectRTokens('q1 == "a//b" || TRUE'));
        // …and a quote inside a comment opens no string.
        $this->assertSame(array('q1', 'q2'), FormV2CompatScanner::namesIn("q1 == 1 // it's\n|| q2 == 2"));
    }
}
