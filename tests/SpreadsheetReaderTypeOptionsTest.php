<?php

/**
 * A named choice list must be recognised whether it is written inline in the
 * `type` cell ("mc_button mylist") or in a separate `type_options` column.
 *
 * The governing principle: a `type_options` column carries exactly what used to
 * be written after the first space of the `type` cell, so the two spellings must
 * parse identically for every type.
 */
class SpreadsheetReaderTypeOptionsTest extends PHPUnit\Framework\TestCase {

    private $files = array();

    protected function tearDown(): void {
        foreach ($this->files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        $this->files = array();
    }

    /**
     * Build a one-item survey workbook with a `choices` sheet defining
     * 'mylist' and 'otherlist'.
     */
    private function makeSheet(array $header, array $row) {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $survey = $spreadsheet->getActiveSheet();
        $survey->setTitle('survey');
        $survey->fromArray(array($header, $row), null, 'A1');

        $choices = $spreadsheet->createSheet();
        $choices->setTitle('choices');
        $choices->fromArray(array(
            array('list_name', 'name', 'label'),
            array('mylist', 'bla', 'bla'),
            array('mylist', 'blu', 'blu'),
            array('otherlist', 'x', 'x'),
        ), null, 'A1');

        $file = tempnam(sys_get_temp_dir(), 'formr_sheet_') . '.xlsx';
        $this->files[] = $file;
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($file);
        return $file;
    }

    /**
     * @return array the single parsed item row, normalized for comparison
     */
    private function parseItem(array $header, array $row) {
        $reader = new SpreadsheetReader();
        $reader->readItemTableFile($this->makeSheet($header, $row));
        $this->assertEmpty($reader->errors, 'reader reported errors: ' . implode(' | ', $reader->errors));
        $this->assertCount(1, $reader->survey, 'expected exactly one parsed item');

        $item = reset($reader->survey);
        return array(
            'type' => isset($item['type']) ? $item['type'] : null,
            'type_options' => isset($item['type_options']) ? (string) $item['type_options'] : '',
            'choice_list' => isset($item['choice_list']) ? $item['choice_list'] : null,
        );
    }

    public static function typeProvider() {
        return array(
            'choice list, mc_button' => array('mc_button mylist'),
            'choice list, mc' => array('mc mylist'),
            'choice list, select_one' => array('select_one mylist'),
            'choice list, select_multiple' => array('select_multiple mylist'),
            'choice list, range' => array('range mylist'),
            'choice list, check' => array('check mylist'),
            'rating_button, list-shaped option' => array('rating_button mylist'),
            'text with width' => array('text 255'),
            'textarea with rows/cols' => array('textarea 5 100'),
            'letters with width' => array('letters 10'),
            'number with two options' => array('number 1 10'),
            'number with comma range' => array('number 1,10'),
            'get with parameter' => array('get referrer'),
            'server with variable' => array('server HTTP_USER_AGENT'),
            'file with mime' => array('file image/*'),
            'submit with class' => array('submit btn-primary'),
            'datetime with bounds' => array('datetime 2020-01-01 2030-01-01'),
        );
    }

    /**
     * The principle: `type` split at the first space must equal
     * `type` + `type_options` written as separate columns.
     *
     * @dataProvider typeProvider
     */
    public function testSeparateTypeOptionsColumnMatchesInlineType($inlineType) {
        $parts = explode(' ', $inlineType, 2);

        $inline = $this->parseItem(
            array('name', 'type', 'label'),
            array('v1', $inlineType, 'Label')
        );
        $split = $this->parseItem(
            array('name', 'type', 'type_options', 'label'),
            array('v1', $parts[0], isset($parts[1]) ? $parts[1] : '', 'Label')
        );

        $this->assertSame($inline, $split, "'{$inlineType}' parses differently when split into a type_options column");
    }

    /**
     * The reported bug: a named list in `type_options` was dropped, so the item
     * came out with no choice list and upload failed with "You forgot to define
     * choices for this item."
     */
    public function testNamedChoiceListInTypeOptionsColumnIsRecognised() {
        $item = $this->parseItem(
            array('name', 'type', 'type_options', 'label'),
            array('v1', 'mc_button', 'mylist', 'Label')
        );

        $this->assertSame('mc_button', $item['type']);
        $this->assertSame('mylist', $item['choice_list']);
    }

    /**
     * The merge must not depend on which column comes first in the sheet.
     */
    public function testTypeOptionsColumnLeftOfTypeColumn() {
        $item = $this->parseItem(
            array('name', 'type_options', 'type', 'label'),
            array('v1', 'mylist', 'mc_button', 'Label')
        );

        $this->assertSame('mc_button', $item['type']);
        $this->assertSame('mylist', $item['choice_list']);
    }

    /**
     * An explicit choice_list column still wins over a list named in type_options.
     */
    public function testExplicitChoiceListColumnWins() {
        $item = $this->parseItem(
            array('name', 'choice_list', 'type', 'type_options', 'label'),
            array('v1', 'otherlist', 'mc_button', 'mylist', 'Label')
        );

        $this->assertSame('otherlist', $item['choice_list']);
    }

    /**
     * An empty type cell must not be back-filled from type_options: that would
     * silently turn the options into the item's type.
     */
    public function testEmptyTypeIsNotFilledFromTypeOptions() {
        $item = $this->parseItem(
            array('name', 'type', 'type_options', 'label'),
            array('v1', '', 'mylist', 'Label')
        );

        $this->assertSame('', $item['type']);
        $this->assertNull($item['choice_list']);
    }
}
