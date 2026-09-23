<?php

require_once __DIR__ . '/helpers/SpreadsheetFixture.php';

/**
 * Two sheet-parsing gaps:
 *
 * - A `choice_list` column with a blank cell must mean "no choice list".
 *   The reader stored the empty string, and Item::validate() (`!== null`)
 *   then rejected every non-choice item in a sheet that merely HAS the
 *   column: "You defined choices for this item, even though this type
 *   doesn't have choices."
 * - `bot_check N` (per-item PoW difficulty, 1..3) was parsed like
 *   `mc mylist`: the numeric token became a choice-list name, so the
 *   difficulty never reached the item and the upload failed on a missing
 *   choice list.
 */
class SpreadsheetReaderBlankChoiceListTest extends PHPUnit\Framework\TestCase {

    use SpreadsheetFixture;

    private function makeSheet(array $rows) {
        return $this->makeWorkbook(array(
            'survey' => $rows,
            'choices' => array(
                array('list_name', 'name', 'label'),
                array('mood', '1', 'bad'),
                array('mood', '2', 'good'),
                array('consent', '1', 'I am human'),
            ),
        ));
    }

    private function parse(array $rows) {
        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($this->makeSheet($rows));
        $this->assertEmpty($reader->errors, 'reader reported errors: ' . implode(' | ', $reader->errors));
        return $reader;
    }

    /** Build items the way SurveyStudy::addItems does and collect validation errors. */
    private function validationErrors(SpreadsheetReader $reader) {
        $lists = array();
        foreach ($reader->choices as $c) {
            $lists[$c['list_name']][$c['name']] = $c['label'];
        }
        $factory = new ItemFactory($lists);
        $errors = array();
        foreach ($reader->survey as $row) {
            $item = $factory->make($row);
            $this->assertNotFalse($item, 'ItemFactory refused row ' . $row['name']);
            $result = $item->validate();
            if (!empty($result['val_errors'])) {
                $errors[$row['name']] = $result['val_errors'];
            }
        }
        return $errors;
    }

    public function testBlankChoiceListCellMeansNoChoiceList() {
        $reader = $this->parse(array(
            array('name', 'type', 'choice_list', 'label'),
            array('q_text', 'text', '', 'Free text'),
            array('q_note', 'note', '', 'Just a note'),
            array('q_mc', 'mc', 'mood', 'How are you?'),
        ));
        $rows = array_column($reader->survey, null, 'name');
        $this->assertNull($rows['q_text']['choice_list']);
        $this->assertNull($rows['q_note']['choice_list']);
        $this->assertSame('mood', $rows['q_mc']['choice_list']);
        $this->assertSame(array(), $this->validationErrors($reader));
    }

    public function testBlankChoiceListLeftOfTypeStillTakesInlineList() {
        $reader = $this->parse(array(
            array('name', 'choice_list', 'type', 'label'),
            array('q_mc', '', 'mc mood', 'How are you?'),
        ));
        $row = reset($reader->survey);
        $this->assertSame('mood', $row['choice_list']);
        $this->assertSame('mc', $row['type']);
    }

    public function testEmptyStringChoiceListIsNotChoicesOnTheItem() {
        // the JSON import path hands rows to ItemFactory without the reader
        $item = (new ItemFactory(array()))->make(array('name' => 'q_text', 'type' => 'text', 'choice_list' => ''));
        $this->assertSame(array(), $item->validate()['val_errors']);
    }

    public function testBotCheckDifficultyIsATypeOptionNotAChoiceList() {
        $reader = $this->parse(array(
            array('name', 'type', 'label'),
            array('human', 'bot_check 3', 'Are you human?'),
            array('human_easy', 'bot_check 1', 'Are you human?'),
            array('human_labelled', 'bot_check consent', 'Are you human?'),
        ));
        $rows = array_column($reader->survey, null, 'name');
        $this->assertSame('3', (string) $rows['human']['type_options']);
        $this->assertNull($rows['human']['choice_list'] ?? null);
        $this->assertSame('1', (string) $rows['human_easy']['type_options']);
        // a named list still relabels the widget (BotCheck::render_input)
        $this->assertSame('consent', $rows['human_labelled']['choice_list']);
        $this->assertSame(array(), $this->validationErrors($reader));
    }
}
