<?php

/**
 * SurveyStudy::addItems used to fold each item's validate() result in with
 * `$this->errors + $val_errors`. validate() returns list arrays, and `+` keeps
 * only the first value at each numeric key, so a sheet with ten invalid items
 * reported exactly one — the author fixed them one re-upload at a time.
 *
 * addItems itself needs MySQL's `ON DUPLICATE KEY UPDATE`, so this drives the
 * extracted per-item step directly.
 */
class SurveyStudyItemValidationTest extends \PHPUnit\Framework\TestCase {

    private function collect(SurveyStudy $study, Item $item): bool {
        $m = new ReflectionMethod(SurveyStudy::class, 'collectItemValidation');
        $m->setAccessible(true);
        return $m->invoke($study, $item);
    }

    private function item(array $row): Item {
        $item = (new ItemFactory(array()))->make($row);
        $this->assertNotFalse($item, 'fixture item must construct');
        return $item;
    }

    public function testEveryInvalidItemIsReported() {
        $study = new SurveyStudy();
        // a name the variable-name rule rejects (one message), then a text
        // item naming a choice list that doesn't exist (two messages: the
        // factory's "list does not exist" and the item's "type has no choices")
        $bad_name = $this->item(array('name' => 'bad-name', 'type' => 'text'));
        $bad_choices = $this->item(array('name' => 'q2', 'type' => 'text', 'choice_list' => 'mood'));

        $this->assertFalse($this->collect($study, $bad_name));
        $this->assertFalse($this->collect($study, $bad_choices));

        // `+` would have kept 2 of these 3 (index 0 of the second item lost)
        $this->assertCount(3, $study->errors, 'no later item\'s message may be dropped');
        $this->assertStringContainsString("'bad-name'", $study->errors[0]);
        $this->assertStringContainsString('Choice list mood does not exist', $study->errors[1]);
        $this->assertStringContainsString("'q2'", $study->errors[2]);
    }

    public function testEveryWarningIsReportedAndTheItemStaysUsable() {
        $study = new SurveyStudy();
        $w1 = $this->item(array('name' => 'q1', 'type' => 'text', 'class' => 'not_a_standard_class'));
        $w2 = $this->item(array('name' => 'q2', 'type' => 'text', 'class' => 'another_odd_class'));

        $this->assertTrue($this->collect($study, $w1));
        $this->assertTrue($this->collect($study, $w2));

        $this->assertSame(array(), $study->errors);
        $this->assertCount(2, $study->warnings);
        $this->assertStringContainsString('not_a_standard_class', $study->warnings[0]);
        $this->assertStringContainsString('another_odd_class', $study->warnings[1]);
    }

    public function testErrorsAppendToWhatTheStudyAlreadyHolds() {
        $study = new SurveyStudy();
        $study->errors[] = 'Row 3: Type nonsense is invalid.';
        $this->assertFalse($this->collect($study, $this->item(array('name' => 'bad-name', 'type' => 'text'))));
        $this->assertCount(2, $study->errors);
        $this->assertSame('Row 3: Type nonsense is invalid.', $study->errors[0]);
    }
}
