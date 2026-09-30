<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/helpers/SpreadsheetFixture.php';

/**
 * A survey sheet whose rows all lack a variable name used to upload
 * "successfully" with no items: every row was skipped, and the only sign was
 * a warning listing each blank row of the sheet as "skipped" too (a blank
 * cell left of `name` counted as content). It is an error now, naming the
 * rows that do have content.
 */
class SpreadsheetReaderNoItemsTest extends TestCase
{
    use SpreadsheetFixture;

    public function testRowsWithoutNamesAreAnErrorNamingThem()
    {
        $path = $this->makeWorkbook(array(
            'survey' => array(
                array('class', 'type', 'name', 'label'),
                array('', 'note', '', 'hello'),
                array('', 'text', '', 'how are you'),
                array('', '', '', ''),
                array('', '', '', ''),
            ),
        ));
        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($path);

        $this->assertSame(array(), $reader->survey);
        $this->assertCount(1, $reader->errors, print_r($reader->errors, true));
        $this->assertStringContainsString('no items', $reader->errors[0]);
        $this->assertStringContainsString('Rows 2, 3 have content', $reader->errors[0], 'blank rows are not listed');
    }

    public function testSkippedRowsBesideItemsStayAWarning()
    {
        $path = $this->makeWorkbook(array(
            'survey' => array(
                array('class', 'type', 'name', 'label'),
                array('', 'text', 'q1', 'First'),
                array('', 'text', '', 'no name'),
                array('', '', '', ''),
            ),
        ));
        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($path);

        $this->assertSame(array(), $reader->errors);
        $this->assertCount(1, $reader->survey);
        $skipped = array_values(array_filter($reader->warnings, function ($w) { return strpos($w, 'Skipped rows') === 0; }));
        $this->assertCount(1, $skipped);
        $this->assertStringContainsString('Skipped rows (no variable name): 3.', $skipped[0]);
    }
}
