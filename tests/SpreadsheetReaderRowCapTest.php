<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/helpers/SpreadsheetFixture.php';

/**
 * Tests for SpreadsheetReader's row-count cap (SpreadsheetReader::MAX_DATA_ROWS).
 *
 * A stray styled/valued cell far down a sheet (e.g. at the Excel max row
 * 1048576) inflates getHighestDataRow(). The row iterator then materializes a
 * Cell object per row/column until PHP exhausts memory — a fatal error that no
 * try/catch can recover. readChoicesSheet()/readSurveySheet() now refuse such a
 * sheet with a graceful error before iterating. These tests prove that, and
 * that ordinary sheets still parse.
 *
 * Fixtures are built programmatically so no binary blob lives in the repo. We
 * place the stray cell with setValue rather than just a style: a value is the
 * stronger trigger and survives the read-only round-trip deterministically,
 * while exercising the exact getHighestDataRow() inflation the cap guards.
 */
class SpreadsheetReaderRowCapTest extends TestCase
{
    use SpreadsheetFixture;

    private const STRAY_ROW = 1048576; // Excel's maximum row index

    /**
     * Workbook from makeWorkbook() with one stray valued cell at column A of
     * STRAY_ROW on $strayOnSheet, so that sheet reports the maximum row count.
     */
    private function bloatedWorkbook(array $sheets, $strayOnSheet)
    {
        return $this->makeWorkbook($sheets, array(
            'cells' => array($strayOnSheet => array('A' . self::STRAY_ROW => 'x')),
        ));
    }

    private function surveyRows()
    {
        return array(
            array('name', 'type', 'label'),
            array('q1', 'text', 'A question'),
        );
    }

    private function choicesRows()
    {
        return array(
            array('list_name', 'name', 'label'),
            array('yesno', 1, 'Yes'),
            array('yesno', 0, 'No'),
        );
    }

    public function testNormalWorkbookParsesWithoutCapError()
    {
        $path = $this->makeWorkbook(array(
            'survey' => $this->surveyRows(),
            'choices' => $this->choicesRows(),
        ));
        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($path);

        $this->assertSame(array(), $reader->errors, 'A normal workbook should produce no errors');
        $this->assertNotEmpty($reader->survey, 'Survey rows should be parsed');
        $this->assertNotEmpty($reader->choices, 'Choice rows should be parsed');
    }

    public function testStrayCellOnChoicesSheetIsRefused()
    {
        $path = $this->bloatedWorkbook(array(
            'survey' => $this->surveyRows(),
            'choices' => $this->choicesRows(),
        ), 'choices');
        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($path);

        $this->assertNotEmpty($reader->errors, 'A bloated choices sheet must be refused, not OOM');
        $joined = implode("\n", $reader->errors);
        $this->assertStringContainsString('choices', $joined);
        $this->assertStringContainsString(number_format(SpreadsheetReader::MAX_DATA_ROWS), $joined);
        $this->assertEmpty($reader->choices, 'No choices should be parsed from a refused sheet');
    }

    public function testChoicesSheetFailureDoesNotCascadeIntoSurveySheet()
    {
        // A bloated choices sheet must produce exactly ONE clean error, not a
        // cascade: previously readSurveySheet() still ran, and its
        // "if ($this->errors)" guard re-wrapped and re-threw the choices error on
        // the first cell, adding the generic "An error occured reading your excel
        // file" message plus a duplicate "Error in cell B2 (Survey Sheet): ...".
        $path = $this->bloatedWorkbook(array(
            'survey' => $this->surveyRows(),
            'choices' => $this->choicesRows(),
        ), 'choices');
        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($path);

        $this->assertCount(1, $reader->errors, 'A bloated choices sheet should yield exactly one error');
        $joined = implode("\n", $reader->errors);
        $this->assertStringNotContainsString('An error occured reading your excel file', $joined);
        $this->assertStringNotContainsString('Survey Sheet', $joined);
    }

    public function testStrayCellOnSurveySheetIsRefused()
    {
        $path = $this->bloatedWorkbook(array(
            'survey' => $this->surveyRows(),
        ), 'survey');
        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($path);

        $this->assertNotEmpty($reader->errors, 'A bloated survey sheet must be refused, not OOM');
        $joined = implode("\n", $reader->errors);
        $this->assertStringContainsString('survey', $joined);
        $this->assertStringContainsString(number_format(SpreadsheetReader::MAX_DATA_ROWS), $joined);
    }
}
