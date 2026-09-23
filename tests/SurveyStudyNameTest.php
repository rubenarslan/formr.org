<?php

/**
 * One rule for survey names, used by upload, rename and the survey-test
 * token alike (the copies had drifted: unanchored on upload, `*` on the
 * token, `{2,64}` on rename).
 */
class SurveyStudyNameTest extends \PHPUnit\Framework\TestCase {

    public static function validNames() {
        return array(array('abc'), array('a_1'), array('Survey_2024_v2'), array(str_repeat('a', 65)));
    }

    public static function invalidNames() {
        return array(
            array(''), array('ab'), array('1abc'), array('_abc'), array('my-study'), array('my study'),
            array('my|study'), array("abc\n"), array('abc.xlsx'), array(str_repeat('a', 66)), array(null), array(array('abc')),
        );
    }

    /** @dataProvider validNames */
    public function testAccepts($name) {
        $this->assertTrue(SurveyStudy::isValidName($name));
    }

    /** @dataProvider invalidNames */
    public function testRejects($name) {
        $this->assertFalse(SurveyStudy::isValidName($name));
    }
}
