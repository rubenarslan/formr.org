<?php

/**
 * FormSubmissionService::stateFromRows: the state of a form's items, as the
 * state block and every submit reply carry it. The names the study's
 * showifs read decide only whether a no-input item's value (random, ip, …)
 * is shipped, so they are read only when such an item is among the rows —
 * a page submit's reply, made of the page's inputs, reads no showif at all.
 */
class FormStateRowsTest extends \PHPUnit\Framework\TestCase {

    private function row($id, $name, $type, $answer) {
        return array('id' => $id, 'study_id' => 1, 'name' => $name, 'type' => $type, 'choice_list' => null,
            'type_options' => null, 'optional' => 1, 'answer' => $answer, 'page' => 1, 'hidden' => 0,
            'saved' => '2026-10-02 10:00:00');
    }

    public function testInputsAloneReadNoShowif() {
        $asked = 0;
        $state = FormSubmissionService::stateFromRows(array($this->row(1, 'q1', 'text', 'x')), function () use (&$asked) {
            $asked++;
            return array();
        });
        $this->assertSame(0, $asked);
        $this->assertSame('x', $state['q1']['value']);
        $this->assertTrue($state['q1']['answered']);
    }

    public function testNoInputItemsAskOnceAndShipOnlyWhatAShowifReads() {
        $asked = 0;
        $rows = array($this->row(1, 'r1', 'random', '2'), $this->row(2, 'r2', 'random', '1'), $this->row(3, 'q1', 'text', 'x'));
        $state = FormSubmissionService::stateFromRows($rows, function () use (&$asked) {
            $asked++;
            return array('r1' => true);
        });
        $this->assertSame(1, $asked, 'the names are read once');
        $this->assertSame('2', $state['r1']['value'], 'a showif reads r1: its value is shipped');
        $this->assertNull($state['r2']['value'], 'none reads r2: its value stays on the server');
        $this->assertTrue($state['r2']['answered']);
    }

    public function testAListOfNamesStillWorks() {
        $state = FormSubmissionService::stateFromRows(array($this->row(1, 'r1', 'random', '2')), array());
        $this->assertNull($state['r1']['value']);
    }
}
