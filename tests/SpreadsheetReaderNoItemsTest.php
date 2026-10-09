<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/helpers/SpreadsheetFixture.php';

/**
 * What an upload reports about rows and columns it does not read (#706).
 * A survey sheet whose rows all lack a variable name is an error (it used
 * to upload "successfully" with no items). Otherwise only rows that look
 * like items (a type or a label) are worth a warning; blank rows, a column
 * filled down past the items and a Google Sheet's unnamed columns are not
 * mentioned at all.
 */
class SpreadsheetReaderNoItemsTest extends TestCase
{
    use SpreadsheetFixture;

    private function read(array $sheets)
    {
        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($this->makeWorkbook($sheets));
        return $reader;
    }

    public function testRowsWithoutNamesAreAnErrorNamingThem()
    {
        $reader = $this->read(array(
            'survey' => array(
                array('class', 'type', 'name', 'optional', 'label'),
                array('', 'note', '', '!', 'hello'),
                array('', 'text', '', '!', 'how are you'),
                array('', '', '', '!', ''),
                array('', '', '', '!', ''),
            ),
        ));

        $this->assertSame(array(), $reader->survey);
        $this->assertCount(1, $reader->errors, print_r($reader->errors, true));
        $this->assertStringContainsString('no items', $reader->errors[0]);
        $this->assertStringContainsString('Rows 2, 3 look like items but have no name', $reader->errors[0], 'rows with only a filled-down column are not listed');
    }

    public function testOnlyRowsThatLookLikeItemsAreWarnedAbout()
    {
        $reader = $this->read(array(
            'survey' => array(
                array('name', 'type', 'optional', 'label', '', ''),
                array('q1', 'text', '!', 'First', '', ''),
                array('', 'text', '!', 'no name', '', ''),
                array('', '', '!', '', '', ''),
                array('', '', '', '', '', ''),
            ),
        ));

        $this->assertSame(array(), $reader->errors);
        $this->assertCount(1, $reader->survey);
        $this->assertSame(array(
            'Row 3 looks like an item but has no name, so it was skipped. Give each item a name in the <code>name</code> column.',
        ), $reader->warnings, 'no warning for blank rows or unnamed columns');
        $this->assertSame(array('Read 1 item.'), $reader->messages);
    }

    public function testBlankChoiceRowsAreNotReported()
    {
        $reader = $this->read(array(
            'survey' => array(
                array('name', 'type', 'label', 'explanations'),
                array('mood', 'mc moods', 'How are you?', 'a note to self'),
            ),
            'choices' => array(
                array('list_name', 'name', 'label'),
                array('moods', '1', 'good'),
                array('', '2', 'bad'),
                array('', '', ''),
                array('', '', ''),
            ),
        ));

        $this->assertSame(array(), $reader->errors, print_r($reader->errors, true));
        $this->assertSame(array(), $reader->warnings);
        $this->assertSame(array('Read 1 item and 1 choice list. Columns formr does not use were ignored: explanations.'), $reader->messages);
    }
    /**
     * Of two columns with one heading — a choices table pasted beside the
     * items brings a second `name` and `label` — the first counts. The row
     * was kept or skipped by the last one, while the first became the
     * item's name: items below the end of the side table vanished.
     */
    public function testTheFirstOfTwoNameColumnsDecides()
    {
        $reader = $this->read(array(
            'survey' => array(
                array('type', 'name', 'label', 'list_name', 'name', 'label'),
                array('mc yesno', 'q1', 'First', 'yesno', '1', 'Yes'),
                array('text', 'q2', 'Second', 'yesno', '0', 'No'),
                array('text', 'q3', 'Third', '', '', ''),
                array('text', 'q4', 'Fourth', '', '', ''),
            ),
            'choices' => array(
                array('list_name', 'name', 'label'),
                array('yesno', '1', 'Yes'),
                array('yesno', '0', 'No'),
            ),
        ));
        $this->assertSame(array('q1', 'q2', 'q3', 'q4'), array_values(array_map(function ($row) { return $row['name']; }, $reader->survey)),
            print_r($reader->errors, true));
    }
}
