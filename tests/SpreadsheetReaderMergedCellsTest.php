<?php

use PHPUnit\Framework\TestCase;

/**
 * #111: an item table with merged cells is refused with a message naming
 * the merged ranges, because merged cells read as blanks and produce
 * misleading errors further down. Covers .xlsx (merge ranges are read from
 * the worksheet XML, since the data-only reader drops them) and .ods (the
 * loaded workbook keeps them).
 */
class SpreadsheetReaderMergedCellsTest extends TestCase
{
    private $tmpFiles = array();

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }
        $this->tmpFiles = array();
    }

    private function makeWorkbook($format, $mergeRange = null)
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('survey');
        $rows = array(
            array('name', 'type', 'label'),
            array('q1', 'text', 'First'),
            array('q2', 'text', 'Second'),
        );
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                $ws->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1) . ($r + 1))->setValue($value);
            }
        }
        if ($mergeRange !== null) {
            $ws->mergeCells($mergeRange);
        }
        $ext = $format === 'Ods' ? '.ods' : '.xlsx';
        $path = tempnam(sys_get_temp_dir(), 'srmerge_') . $ext;
        $this->tmpFiles[] = $path;
        \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, $format)->save($path);
        $spreadsheet->disconnectWorksheets();
        return $path;
    }

    public function testXlsxWithMergedCellsIsRefusedNamingTheRange()
    {
        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($this->makeWorkbook('Xlsx', 'C2:D2'));

        $this->assertCount(1, $reader->errors, print_r($reader->errors, true));
        $this->assertStringContainsString('merged cells', $reader->errors[0]);
        $this->assertStringContainsString('C2:D2', $reader->errors[0]);
        $this->assertSame(array(), $reader->survey, 'Nothing is parsed from a refused upload');
    }

    public function testOdsWithMergedCellsIsRefused()
    {
        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($this->makeWorkbook('Ods', 'C2:D2'));

        $this->assertCount(1, $reader->errors, print_r($reader->errors, true));
        $this->assertStringContainsString('C2:D2', $reader->errors[0]);
    }

    public function testWorkbookWithoutMergedCellsStillParses()
    {
        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($this->makeWorkbook('Xlsx'));

        $this->assertSame(array(), $reader->errors, print_r($reader->errors, true));
        $this->assertCount(2, $reader->survey);
    }
}
