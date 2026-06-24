<?php
use PHPUnit\Framework\TestCase;

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
    private $tmpFiles = array();

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->tmpFiles = array();
    }

    private const STRAY_ROW = 1048576; // Excel's maximum row index

    /**
     * Write an .xlsx to a temp path. $sheets maps sheet title => array of rows,
     * each row an array of cell values (row 1 is the header). If $strayOnSheet
     * is given, an extra cell is set at column A of STRAY_ROW on that sheet to
     * inflate its reported data-row count.
     */
    private function makeWorkbook(array $sheets, $strayOnSheet = null)
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);
        foreach ($sheets as $title => $rows) {
            $ws = $spreadsheet->createSheet();
            $ws->setTitle($title);
            $r = 1;
            foreach ($rows as $row) {
                $c = 1;
                foreach ($row as $value) {
                    $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $r;
                    $ws->getCell($coord)->setValue($value);
                    $c++;
                }
                $r++;
            }
            if ($strayOnSheet === $title) {
                $ws->getCell('A' . self::STRAY_ROW)->setValue('x');
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'srtest_') . '.xlsx';
        $this->tmpFiles[] = $path;
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();
        return $path;
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
        $path = $this->makeWorkbook(array(
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
        $path = $this->makeWorkbook(array(
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
        $path = $this->makeWorkbook(array(
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
