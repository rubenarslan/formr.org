<?php

require_once __DIR__ . '/helpers/SpreadsheetFixture.php';

/**
 * A spreadsheet's `choice_list` column — the column the sheet export writes
 * the list into, ahead of `type` (SurveyStudy::getItemsForSheet) — is the
 * item's list name: it is not an inline choice, and with it set the token
 * after the type is an option, not a list.
 */
class SpreadsheetChoiceListColumnTest extends \PHPUnit\Framework\TestCase {

    use SpreadsheetFixture;

    private function read(array $survey) {
        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($this->makeWorkbook(array(
            'survey' => $survey,
            'choices' => array(array('list_name', 'name', 'label'),
                array('yn', '1', 'yes'), array('yn', '2', 'no'), array('zipmsg', '1', 'Five digits, please')),
        )));
        $this->assertEmpty($reader->errors, implode(' | ', $reader->errors));
        $items = array();
        foreach ($reader->survey as $row) { $items[$row['name']] = $row; }
        return array($reader, $items);
    }

    public function testTheColumnIsTheListNameNotAnInlineChoice() {
        list($reader, $items) = $this->read(array(
            array('choice_list', 'type', 'name', 'label'),
            array('yn', 'mc', 'q1', 'Q1'),
        ));
        $this->assertSame('yn', $items['q1']['choice_list']);
        $lists = array_unique(array_column($reader->choices, 'list_name'));
        $this->assertNotContains('q1', $lists, 'no inline choice list named after the item');
        $this->assertNotContains('_list', array_column($reader->choices, 'name'));
    }

    public function testATextItemKeepsItsPatternAndItsMessageList() {
        list(, $items) = $this->read(array(
            array('choice_list', 'type', 'name', 'label'),
            array('zipmsg', 'text [0-9]{5}', 'zip', 'ZIP'),
        ));
        $this->assertSame('text', $items['zip']['type']);
        $this->assertSame('[0-9]{5}', $items['zip']['type_options']);
        $this->assertSame('zipmsg', $items['zip']['choice_list']);
    }

    public function testWithAListSetTheTokenAfterTheTypeIsAnOption() {
        list(, $items) = $this->read(array(
            array('choice_list', 'type', 'name', 'label'),
            array('yn', 'select_or_add_one 3', 'q2', 'Q2'),
        ));
        $this->assertSame('yn', $items['q2']['choice_list']);
        $this->assertSame('3', $items['q2']['type_options'], 'the option is kept, not dropped as a would-be list');
    }

    public function testAListAfterTheTypeStillWorksWithoutTheColumn() {
        list(, $items) = $this->read(array(
            array('type', 'name', 'label'),
            array('mc yn', 'q3', 'Q3'),
        ));
        $this->assertSame('mc', $items['q3']['type']);
        $this->assertSame('yn', $items['q3']['choice_list']);
    }
}
