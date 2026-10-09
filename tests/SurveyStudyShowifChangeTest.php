<?php

use PHPUnit\Framework\TestCase;

/**
 * SurveyStudy::showifChanged() decides which items get their cached
 * per-session showif verdicts (survey_items_display.hidden) reset when an
 * item table is re-uploaded (v1.10.3). Too eager and every upload thrashes
 * every unfinished session; too lazy and a corrected showif keeps not
 * applying — the bug this exists for.
 */
class SurveyStudyShowifChangeTest extends TestCase
{
    public function testAbsentEmptyAndBlankAreTheSameNoShowif()
    {
        $this->assertFalse(SurveyStudy::showifChanged(null, null));
        $this->assertFalse(SurveyStudy::showifChanged(null, ''));
        $this->assertFalse(SurveyStudy::showifChanged('', "  \n"));
        $this->assertFalse(SurveyStudy::showifChanged("\t", null));
    }

    public function testIdenticalCodeIsUnchangedEvenWithSurroundingWhitespace()
    {
        $this->assertFalse(SurveyStudy::showifChanged('gate == 1', 'gate == 1'));
        $this->assertFalse(SurveyStudy::showifChanged("gate == 1\n", '  gate == 1'));
    }

    public function testAnyEditAddedOrRemovedShowifIsAChange()
    {
        $this->assertTrue(SurveyStudy::showifChanged('gate == 1', 'gate == 2'));
        $this->assertTrue(SurveyStudy::showifChanged(null, 'gate == 1'), 'a showif added to an item');
        $this->assertTrue(SurveyStudy::showifChanged('gate == 1', ''), 'a showif removed from an item');
        $this->assertTrue(SurveyStudy::showifChanged('gate == 1', 'gate ==  1'), 'inner whitespace is code');
    }
}
