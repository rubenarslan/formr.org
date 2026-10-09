<?php

/**
 * Two ways a v2 answer used to be stored other than it was given:
 *   - a multiple choice's value "0" was dropped (v1 keeps doing so, so its
 *     results do not change shape; v2 stores it, and sizes its column for it);
 *   - an answer the participant cleared stayed stored, because autosave
 *     never posted "empty"; withdrawnNames picks those items out.
 */
class FormV2AnswerShapeTest extends \PHPUnit\Framework\TestCase {

    private function item($type, array $extra = array()) {
        $lists = array('zero' => array('0' => 'none', '1' => 'one', '2' => 'two', '3' => 'three'));
        return (new ItemFactory($lists))->make($extra + array('id' => 1, 'study_id' => 1, 'name' => 'x', 'type' => $type, 'label' => 'x'));
    }

    public function testV1DropsTheZeroChoiceAsItAlwaysHas() {
        foreach (array('mc_multiple', 'mc_multiple_button', 'select_multiple') as $type) {
            $item = $this->item($type, array('choice_list' => 'zero'));
            $this->assertSame('2', $item->getReply(array('', '0', '2')), $type);
        }
    }

    public function testV2StoresTheZeroChoice() {
        foreach (array('mc_multiple', 'mc_multiple_button', 'select_multiple') as $type) {
            $item = $this->item($type, array('choice_list' => 'zero'));
            $item->formV2 = true;
            $this->assertSame('0, 2', $item->getReply(array('', '0', '2')), $type);
            $this->assertSame('0', $item->getReply(array('', '0')), $type . ': only 0 ticked');
            $this->assertSame('', $item->getReply(array('')), $type . ': nothing ticked');
        }
        $add = $this->item('select_or_add_multiple');
        $add->formV2 = true;
        $this->assertSame("0\nBerlin", $add->getReply(array('', '0', 'Berlin')));
    }

    public function testTheColumnFitsEveryChoiceIncludingZero() {
        foreach (array('mc_multiple', 'select_multiple') as $type) {
            $item = $this->item($type, array('choice_list' => 'zero'));
            $this->assertSame('`x` VARCHAR (10) DEFAULT NULL', $item->getResultField(), $type . ': strlen("0, 1, 2, 3")');
        }
    }

    public function testAYearBoundStaysWithinTheYearColumn() {
        // The column is a YEAR (1901-2155): a wider bound would accept a year
        // whose write then fails the whole page.
        $year = $this->item('year', array('type_options' => '1850, 2300'));
        $this->assertSame(1901, $year->input_attributes['min']);
        $this->assertSame(2155, $year->input_attributes['max']);
        $year->validateInput('1880');
        $this->assertNotEmpty($year->error, '1880 does not fit a YEAR column');

        $narrow = $this->item('year', array('type_options' => '1950, 2010'));
        $this->assertSame(1950, $narrow->input_attributes['min']);
        $this->assertSame(2010, $narrow->input_attributes['max']);
        $this->assertSame('1985', $narrow->validateInput('1985'));
        $this->assertEmpty($narrow->error);
    }

    public function testAnEmptiedAnswerIsOneToWithdraw() {
        $items = array(
            't' => $this->item('text', array('name' => 't')),
            'mc' => $this->item('mc', array('name' => 'mc', 'choice_list' => 'zero')),
            'mm' => $this->item('mc_multiple', array('name' => 'mm', 'choice_list' => 'zero')),
            'filled' => $this->item('text', array('name' => 'filled')),
            'cb' => $this->item('check', array('name' => 'cb')),
            'f' => $this->item('file', array('name' => 'f')),
            'n' => $this->item('note', array('name' => 'n')),
            'b' => $this->item('bot_check', array('name' => 'b')),
        );
        $posted = array('t' => '', 'mc' => '', 'mm' => array(''), 'filled' => 'x', 'cb' => '0', 'f' => '', 'n' => '', 'b' => '', 'ghost' => '');
        $this->assertSame(array('t', 'mc', 'mm'), FormSubmissionService::withdrawnNames($posted, $items),
            'emptied text, deselected choice, no box ticked; not an answer, an unchecked check (its "0" is an answer), an upload, a note, a bot_check or an unknown name');
    }
}
