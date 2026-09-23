<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/helpers/SpreadsheetFixture.php';

/**
 * Regression for #385: a duplicate variable name used to be reported three
 * times — the bare row message, a generic "An error occured reading your
 * excel file" line, and the cell-located copy that wraps the row message.
 * The reader now keeps only the cell-located message.
 */
class SpreadsheetReaderDuplicateNameTest extends TestCase
{
    use SpreadsheetFixture;

    public function testDuplicateVariableNameIsReportedOnce()
    {
        $path = $this->makeWorkbook(array(
            'survey' => array(
                array('name', 'type', 'label'),
                array('q1', 'text', 'First'),
                array('q2', 'text', 'Second'),
                array('q1', 'text', 'Third, duplicate name'),
            ),
        ));
        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($path);

        $this->assertCount(1, $reader->errors, 'One mistake should yield one error message: ' . print_r($reader->errors, true));
        $this->assertStringContainsString("Variable name 'q1' already appeared in row 2", $reader->errors[0]);
        $this->assertStringContainsString('Error in cell', $reader->errors[0], 'The surviving message is the cell-located one');
        $this->assertStringNotContainsString('An error occur', $reader->errors[0]);
    }
}
