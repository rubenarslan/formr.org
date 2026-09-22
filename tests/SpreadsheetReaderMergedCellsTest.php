<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/helpers/SpreadsheetFixture.php';

/**
 * #111: an item table with merged cells is refused with a message naming
 * the merged ranges, because merged cells read as blanks and produce
 * misleading errors further down. Covers .xlsx (merge ranges are read from
 * the worksheet XML, since the data-only reader drops them) and .ods (the
 * loaded workbook keeps them).
 */
class SpreadsheetReaderMergedCellsTest extends TestCase
{
    use SpreadsheetFixture;

    /**
     * Two-item survey workbook. $mergeRange merges cells on the survey tab.
     * With $extraTitle an unread extra tab is added (plus a 'choices' tab,
     * because without one the reader treats tab 2 as the choices sheet);
     * $extraMerge merges cells on that extra tab, $extraFirst puts it before
     * the survey tab.
     */
    private function workbook($format, $mergeRange = null, $extraTitle = null, $extraMerge = null, $extraFirst = false)
    {
        $survey = array(
            array('name', 'type', 'label'),
            array('q1', 'text', 'First'),
            array('q2', 'text', 'Second'),
        );
        $sheets = array('survey' => $survey);
        $merges = array();
        if ($mergeRange !== null) {
            $merges['survey'] = $mergeRange;
        }
        if ($extraTitle !== null) {
            $extra = array($extraTitle => array(array('ignored')));
            $sheets = $extraFirst ? $extra + $sheets : $sheets + $extra;
            $sheets['choices'] = array(array('list_name', 'name', 'label'));
            if ($extraMerge !== null) {
                $merges[$extraTitle] = $extraMerge;
            }
        }
        return $this->makeWorkbook($sheets, array('format' => $format, 'merge' => $merges));
    }

    public function testXlsxWithMergedCellsIsRefusedNamingTheRange()
    {
        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($this->workbook('Xlsx', 'C2:D2'));

        $this->assertCount(1, $reader->errors, print_r($reader->errors, true));
        $this->assertStringContainsString('merged cells', $reader->errors[0]);
        $this->assertStringContainsString('C2:D2', $reader->errors[0]);
        $this->assertSame(array(), $reader->survey, 'Nothing is parsed from a refused upload');
    }

    public function testOdsWithMergedCellsIsRefused()
    {
        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($this->workbook('Ods', 'C2:D2'));

        $this->assertCount(1, $reader->errors, print_r($reader->errors, true));
        $this->assertStringContainsString('C2:D2', $reader->errors[0]);
    }

    public function testMergeOnAnUnreadSheetDoesNotBlockTheUpload()
    {
        // Only the sheets the reader parses are inspected: a notes tab
        // with a merged title row swallows nothing formr reads.
        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($this->workbook('Xlsx', null, 'notes', 'A1:C1'));
        $this->assertSame(array(), $reader->errors, print_r($reader->errors, true));
        $this->assertCount(2, $reader->survey);
    }

    public function testXlsxRangeIsLabelledWithTheSheetTitleNotThePartNumber()
    {
        // 'survey' is the second tab. The error must name the tab the user
        // sees, not a "worksheet N" part number: Excel keeps the number a
        // sheet was created with, so in real files it need not match the
        // tab order (PhpSpreadsheet's writer renumbers in tab order, so this
        // fixture pins the label, not the mismatch itself).
        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($this->workbook('Xlsx', 'C2:D2', 'notes', null, true));
        $this->assertCount(1, $reader->errors, print_r($reader->errors, true));
        $this->assertStringContainsString('survey!C2:D2', $reader->errors[0]);
        $this->assertStringNotContainsString('worksheet', $reader->errors[0]);
    }

    public function testWorkbookWithoutMergedCellsStillParses()
    {
        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($this->workbook('Xlsx'));

        $this->assertSame(array(), $reader->errors, print_r($reader->errors, true));
        $this->assertCount(2, $reader->survey);
    }
}
