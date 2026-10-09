<?php
use PHPUnit\Framework\TestCase;

/**
 * A study's own Next / Submit wording (#322) replaces the built-in strings
 * whenever it is set — "0" included, which SurveyStudy::normalizeButtonLabel
 * stores as a label and empty() took for unset.
 */
class FormStringsOverridesTest extends TestCase
{
    private function study($next, $submit)
    {
        $study = (new ReflectionClass(SurveyStudy::class))->newInstanceWithoutConstructor();
        $study->next_button_label = $next;
        $study->submit_button_label = $submit;
        return $study;
    }

    public function testEveryLabelThatIsSetIsUsed()
    {
        $this->assertSame(array('nav.next' => '0', 'nav.continue' => '0', 'nav.submit' => '0'),
            FormStrings::studyOverrides($this->study('0', '0')));
        $this->assertSame(array('nav.next' => 'Weiter', 'nav.continue' => 'Weiter'),
            FormStrings::studyOverrides($this->study('Weiter', null)));
        $this->assertSame(array(), FormStrings::studyOverrides($this->study(null, '')));
        $this->assertSame(array(), FormStrings::studyOverrides(null));
    }
}
