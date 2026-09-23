<?php
/**
 * Shared workbook builder for SpreadsheetReader tests.
 *
 * Every reader test used to carry its own PhpSpreadsheet -> tempnam ->
 * unlink boilerplate. This trait owns it: build the workbook in memory,
 * write it to a temp file, hand back the path, and remove the file after
 * the test (PHPUnit's #[After] hook, so no tearDown is needed).
 *
 *   require_once __DIR__ . '/helpers/SpreadsheetFixture.php';
 *   class MyTest extends TestCase { use SpreadsheetFixture; ... }
 *   $path = $this->makeWorkbook(array('survey' => $rows, 'choices' => $rows));
 *
 * $sheets maps tab title => rows (array of arrays), created in array order,
 * so the first key is the first tab. Options:
 *   'format' => 'Xlsx' (default) or 'Ods'
 *   'merge'  => array(title => 'A1:B1' or array of ranges) merged after the rows
 *   'cells'  => array(title => array('A1048576' => 'x')) single cells set after the rows
 */
trait SpreadsheetFixture
{
    private $spreadsheetFixtureFiles = array();

    #[\PHPUnit\Framework\Attributes\After]
    protected function removeSpreadsheetFixtures(): void
    {
        foreach ($this->spreadsheetFixtureFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        $this->spreadsheetFixtureFiles = array();
    }

    protected function makeWorkbook(array $sheets, array $options = array())
    {
        $format = isset($options['format']) ? $options['format'] : 'Xlsx';
        $merges = isset($options['merge']) ? $options['merge'] : array();
        $cells = isset($options['cells']) ? $options['cells'] : array();

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);
        foreach ($sheets as $title => $rows) {
            $ws = $spreadsheet->createSheet();
            $ws->setTitle($title);
            if ($rows) {
                $ws->fromArray($rows, null, 'A1');
            }
            foreach ((array) (isset($merges[$title]) ? $merges[$title] : array()) as $range) {
                $ws->mergeCells($range);
            }
            foreach ((isset($cells[$title]) ? $cells[$title] : array()) as $coord => $value) {
                $ws->getCell($coord)->setValue($value);
            }
        }

        // tempnam() creates its (extension-less) file; the workbook is written
        // beside it under the real extension, so both must be removed — the
        // old per-test builders forgot the base file and leaked one per run.
        $base = tempnam(sys_get_temp_dir(), 'formr_sheet_');
        $path = $base . ($format === 'Ods' ? '.ods' : '.xlsx');
        $this->spreadsheetFixtureFiles[] = $base;
        $this->spreadsheetFixtureFiles[] = $path;
        \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, $format)->save($path);
        $spreadsheet->disconnectWorksheets();
        return $path;
    }
}
