<?php

class SpreadsheetReader {

    private $choices_columns = array('list_name', 'name', 'label');
    private $survey_columns = array('name', 'type', 'label', 'optional', 'class', 'showif', 'choice1', 'choice2', 'choice3', 'choice4', 'choice5', 'choice6', 'choice7', 'choice8', 'choice9', 'choice10', 'choice11', 'choice12', 'choice13', 'choice14', 'value', 'order', 'block_order', 'item_order');
    private $internal_columns = array('choice_list', 'type_options', 'label_parsed');
    private $existing_choice_lists = array();
    public $messages = array();
    public $errors = array();
    public $warnings = array();
    public $survey = array();
    public $choices = array();
    public static $exportFormats = array('csv', 'csv_german', 'tsv', 'xlsx', 'xls', 'json');
    
    public $parsedown;
    
    public function __construct() {
        $this->parsedown = new ParsedownExtra();
        $this->parsedown = $this->parsedown->setBreaksEnabled(true)->setUrlsLinked(true);
    }

    public static function verifyExportFormat($formatstring) {
        if (!in_array($formatstring, static::$exportFormats)) {
            formr_error(400, 'Bad Request', 'Unsupported export format requested.');
        }
    }

    public function backupTSV($array, $filename) {
        $objPhpSpreadsheet = $this->objectFromArray($array);

        $objWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($objPhpSpreadsheet, 'Csv');
        /** @var \PhpOffice\PhpSpreadsheet\Writer\Csv $objWriter */
        $objWriter->setDelimiter("\t");
        $objWriter->setEnclosure("");

        try {
            $objWriter->save($filename);
            return true;
        } catch (Exception $e) {
            formr_log_exception($e, __CLASS__);
            alert("Couldn't save file.", 'alert-danger');
            return false;
        }
    }

    protected function objectFromArray($array) {
        set_time_limit(300); # defaults to 30
        ini_set('memory_limit', Config::get('memory_limit.spr_object_array'));

        $objPhpSpreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $current = current($array);
        if (!$current) {
            return $objPhpSpreadsheet;
        }
        array_unshift($array, array_keys($current));
        $objPhpSpreadsheet->getSheet(0)->fromArray($array);

        return $objPhpSpreadsheet;
    }

    /**
     * 
     * @param PDOStatement $stmt
     * @return \PhpOffice\PhpSpreadsheet\Spreadsheet
     */
    protected function objectFromPDOStatement(PDOStatement $stmt) {
        $PhpSpreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $PhpSpreadsheetSheet = $PhpSpreadsheet->getSheet(0);

        list ($startColumn, $startRow) = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString('A1');
        $writeColumns = true;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($writeColumns) {
                $columns = array_keys($row);
                $currentColumn = $startColumn;
                foreach ($columns as $cellValue) {
                    $PhpSpreadsheetSheet->getCell($currentColumn . $startRow)->setValue($cellValue);
                    ++$currentColumn;
                }
                ++$startRow;
                $writeColumns = false;
            }
            $currentColumn = $startColumn;
            foreach ($row as $cellValue) {
                $PhpSpreadsheetSheet->getCell($currentColumn . $startRow)->setValue($cellValue);
                ++$currentColumn;
            }
            ++$startRow;
        }
        return $PhpSpreadsheet;
    }

    public function exportInRequestedFormat(PDOStatement $resultsStmt, $filename, $filetype) {
        self::verifyExportFormat($filetype);

        switch ($filetype) {
            case 'xlsx':
                $download_successfull = $this->exportXLSX($resultsStmt, $filename);
                break;
            case 'xls':
                $download_successfull = $this->exportXLS($resultsStmt, $filename);
                break;
            case 'csv_german':
                $download_successfull = $this->exportCSV_german($resultsStmt, $filename);
                break;
            case 'tsv':
                $download_successfull = $this->exportTSV($resultsStmt, $filename);
                break;
            case 'json':
                $download_successfull = $this->exportJSON($resultsStmt, $filename);
                break;
            default:
                $download_successfull = $this->exportCSV($resultsStmt, $filename);
                break;
        }


        return $download_successfull;
    }

    public function exportCSV(PDOStatement $stmt, $filename) {
        if (!$stmt->columnCount()) {
            formr_log('Debug: column count is not set');
            return false;
        }

        try {
            $phpSpreadsheet = $this->objectFromPDOStatement($stmt);
            $phpSpreadsheetWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($phpSpreadsheet, 'Csv');

            header('Content-Disposition: attachment;filename="' . $filename . '.csv"');
            header('Cache-Control: max-age=0');
            header('Content-Type: text/csv');
            $phpSpreadsheetWriter->save('php://output');
            exit;
        } catch (Exception $e) {
            formr_log_exception($e, __METHOD__);
            alert('Couldn\'t save file.', 'alert-danger');
            return false;
        }
    }

    public function exportJSON($object, $filename) {
        set_time_limit(300);
        if ($object instanceof PDOStatement) {
            $file = APPLICATION_ROOT . "tmp/downloads/{$filename}.json";
            file_put_contents($file, '');

            $handle = fopen($file, 'w+');
            while ($row = $object->fetch(PDO::FETCH_ASSOC)) {
                fwrite_json($handle, $row);
            }
            fclose($handle);

            header('Content-Disposition: attachment;filename="' . $filename . '.json"');
            header('Cache-Control: max-age=0');
            header('Content-type: application/json; charset=utf-8');
            Config::get('use_xsendfile') ? header('X-Sendfile: ' . $file) : readfile($file);
            exit;
        } else {
            header('Content-Disposition: attachment;filename="' . $filename . '.json"');
            header('Cache-Control: max-age=0');
            header('Content-type: application/json; charset=utf-8');
            echo json_encode($object, JSON_PRETTY_PRINT + JSON_UNESCAPED_UNICODE + JSON_NUMERIC_CHECK);
            exit;
        }
    }

    public function exportTSV(PDOStatement $stmt, $filename, $savefile = null) {
        if (!$stmt->columnCount()) {
            return false;
        }

        try {
            $phpSpreadsheet = $this->objectFromPDOStatement($stmt);
            /** @var \PhpOffice\PhpSpreadsheet\Writer\Csv $phpSpreadsheetWriter */
            $phpSpreadsheetWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($phpSpreadsheet, 'Csv');
            $phpSpreadsheetWriter->setDelimiter("\t");
            $phpSpreadsheetWriter->setEnclosure("");

            if ($savefile === null) {
                header('Content-Disposition: attachment;filename="' . $filename . '.tab"');
                header('Cache-Control: max-age=0');
                header('Content-Type: text/csv'); // or maybe text/tab-separated-values?
                $phpSpreadsheetWriter->save('php://output');
                exit;
            } else {
                $phpSpreadsheetWriter->save($savefile);
                return true;
            }
        } catch (Exception $e) {
            formr_log_exception($e, __METHOD__);
            alert('Couldn\'t save file.', 'alert-danger');
            return false;
        }
    }

    public function exportCSV_german(PDOStatement $stmt, $filename, $savefile = null) {
        if (!$stmt->columnCount()) {
            return false;
        }

        try {
            $phpSpreadsheet = $this->objectFromPDOStatement($stmt);
            /** @var \PhpOffice\PhpSpreadsheet\Writer\Csv $phpSpreadsheetWriter */
            $phpSpreadsheetWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($phpSpreadsheet, 'Csv');
            $phpSpreadsheetWriter->setDelimiter(';');
            $phpSpreadsheetWriter->setEnclosure('"');

            if ($savefile === null) {
                header('Content-Disposition: attachment;filename="' . $filename . '.csv"');
                header('Cache-Control: max-age=0');
                header('Content-Type: text/csv');
                $phpSpreadsheetWriter->save('php://output');
                exit;
            } else {
                $phpSpreadsheetWriter->save($savefile);
                return true;
            }
        } catch (Exception $e) {
            formr_log_exception($e, __METHOD__);
            alert('Couldn\'t save file.', 'alert-danger');
            return false;
        }
    }

    public function exportXLS(PDOStatement $stmt, $filename) {
        if (!$stmt->columnCount()) {
            return false;
        }

        try {
            $phpSpreadsheet = $this->objectFromPDOStatement($stmt);
            $phpSpreadsheetWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($phpSpreadsheet, 'Xls');
            header('Content-Disposition: attachment;filename="' . $filename . '.xls"');
            header('Cache-Control: max-age=0');
            header('Content-Type: application/vnd.ms-excel');
            $phpSpreadsheetWriter->save('php://output');
            exit;
        } catch (Exception $e) {
            formr_log_exception($e, __METHOD__);
            alert('Couldn\'t save file.', 'alert-danger');
            return false;
        }
    }

    public function exportXLSX(PDOStatement $stmt, $filename) {
        if (!$stmt->columnCount()) {
            return false;
        }

        try {
            $phpSpreadsheet = $this->objectFromPDOStatement($stmt);
            $phpSpreadsheetWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($phpSpreadsheet, 'Xlsx');
            header('Content-Disposition: attachment;filename="' . $filename . '.xlsx"');
            header('Cache-Control: max-age=0');
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $phpSpreadsheetWriter->save('php://output');
            exit;
        } catch (Exception $e) {
            formr_log_exception($e, __METHOD__);
            alert('Couldn\'t save file.', 'alert-danger');
            return false;
        }
    }

    private function getSheetsFromArrays($items, $choices = array(), $settings = array()) {
        set_time_limit(300); # defaults to 30
        ini_set('memory_limit', Config::get('memory_limit.spr_sheets_array'));

        $objPhpSpreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $objPhpSpreadsheet->getDefaultStyle()->getFont()->setName('Helvetica');
        $objPhpSpreadsheet->getDefaultStyle()->getFont()->setSize(16);
        $objPhpSpreadsheet->getDefaultStyle()->getAlignment()->setWrapText(true);
        $sheet_index = $objPhpSpreadsheet->getSheetCount() - 1;

        if (is_array($choices) && count($choices) > 0):
            $objPhpSpreadsheet->createSheet();
            $sheet_index++;
            array_unshift($choices, array_keys(current($choices)));
            $objPhpSpreadsheet->getSheet($sheet_index)->getDefaultColumnDimension()->setWidth(20);
            $objPhpSpreadsheet->getSheet($sheet_index)->getColumnDimension('A')->setWidth(20); # list_name
            $objPhpSpreadsheet->getSheet($sheet_index)->getColumnDimension('B')->setWidth(20); # name
            $objPhpSpreadsheet->getSheet($sheet_index)->getColumnDimension('C')->setWidth(30); # label

            $objPhpSpreadsheet->getSheet($sheet_index)->fromArray($choices);
            $objPhpSpreadsheet->getSheet($sheet_index)->setTitle('choices');
            $objPhpSpreadsheet->getSheet($sheet_index)->getStyle('A1:C1')->applyFromArray(array('font' => array('bold' => true)));
        endif;

        if (is_array($settings) && count($settings) > 0):
            // put settings in a suitable format for excel sheet
            $sttgs = array(array('item', 'value'));
            foreach ($settings as $item => $value) {
                $sttgs[] = array('item' => $item, 'value' => (string) $value);
            }

            $objPhpSpreadsheet->createSheet();
            $sheet_index++;
            $objPhpSpreadsheet->getSheet($sheet_index)->getDefaultColumnDimension()->setWidth(20);
            $objPhpSpreadsheet->getSheet($sheet_index)->getColumnDimension('A')->setWidth(20); # item
            $objPhpSpreadsheet->getSheet($sheet_index)->getColumnDimension('B')->setWidth(20); # value

            $objPhpSpreadsheet->getSheet($sheet_index)->fromArray($sttgs);
            $objPhpSpreadsheet->getSheet($sheet_index)->setTitle('settings');
            $objPhpSpreadsheet->getSheet($sheet_index)->getStyle('A1:C1')->applyFromArray(array('font' => array('bold' => true)));
        endif;

        array_unshift($items, array_keys(current($items)));
        $objPhpSpreadsheet->getSheet(0)->getColumnDimension('A')->setWidth(20); # type
        $objPhpSpreadsheet->getSheet(0)->getColumnDimension('B')->setWidth(20); # name
        $objPhpSpreadsheet->getSheet(0)->getColumnDimension('C')->setWidth(30); # label
        $objPhpSpreadsheet->getSheet(0)->getColumnDimension('D')->setWidth(3);  # optional
        $objPhpSpreadsheet->getSheet(0)->getStyle('D1')->getAlignment()->setWrapText(false);

        $objPhpSpreadsheet->getSheet(0)->fromArray($items);
        $objPhpSpreadsheet->getSheet(0)->setTitle('survey');
        $objPhpSpreadsheet->getSheet(0)->getStyle('A1:H1')->applyFromArray(array('font' => array('bold' => true)));

        return $objPhpSpreadsheet;
    }

    public function exportItemTableXLSX(SurveyStudy $study) {
        $items = $study->getItemsForSheet();
        $choices = $study->getChoicesForSheet();
        $filename = $study->name;

        try {
            $objPhpSpreadsheet = $this->getSheetsFromArrays($items, $choices, $study->getSettings());
            $objWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($objPhpSpreadsheet, 'Xlsx');

            header('Content-Disposition: attachment;filename="' . $filename . '.xlsx"');
            header('Cache-Control: max-age=0');
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

            $objWriter->save('php://output');
            exit;
        } catch (Exception $e) {
            formr_log_exception($e, __CLASS__);
            alert("Couldn't save file.", 'alert-danger');
            return false;
        }
    }

    public function exportItemTableXLS(SurveyStudy $study) {
        $items = $study->getItemsForSheet();
        $choices = $study->getChoicesForSheet();
        $filename = $study->name;

        try {
            $objPhpSpreadsheet = $this->getSheetsFromArrays($items, $choices, $study->getSettings());

            $objWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($objPhpSpreadsheet, 'Xls');

            header('Content-Disposition: attachment;filename="' . $filename . '.xls"');
            header('Cache-Control: max-age=0');
            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
            $objWriter->save('php://output');
            exit;
        } catch (Exception $e) {
            formr_log_exception($e, __CLASS__);
            alert("Couldn't save file.", 'alert-danger');
            return false;
        }
    }

    public function exportItemTableJSON(SurveyStudy $study, $return_object = false) {
        $items = $study->getItems();
        $choices = $study->getChoices();
        $filename = $study->name;

        foreach ($items as $i => $val) {
            unset($items[$i]['id'], $items[$i]['study_id']);
            if (isset($val["choice_list"]) && isset($choices[$val["choice_list"]])) {
                $items[$i]["choices"] = $choices[$val["choice_list"]];
                $items[$i]["choice_list"] = $items[$i]["name"];
            }
        }

        $object = array(
            'name' => $study->name,
            'items' => $items,
            'settings' => $study->getSettings(),
        );

        if ($google_id = $study->getGoogleFileId()) {
            $object['google_sheet'] = google_get_sheet_link($google_id);
        }

        if ($return_object === true) {
            return $object;
        }

        header('Content-Disposition: attachment;filename="' . $filename . '.json"');
        header('Cache-Control: max-age=0');
        header('Content-type: application/json; charset=utf-8');

        try {
            echo json_encode($object, JSON_PRETTY_PRINT + JSON_UNESCAPED_UNICODE + JSON_NUMERIC_CHECK);
            exit;
        } catch (Exception $e) {
            formr_log_exception($e, __CLASS__);
            alert("Couldn't save file.", 'alert-danger');
            return false;
        }
    }

    public function addSurveyItem(array $row) {
        // @todo validate items in $data
        if (empty($row['name'])) {
            $this->warnings[] = "Skipping row with no 'item name' specified";
            return;
        }

        foreach ($row as $key => $value) {
            if (!in_array($key, $this->survey_columns) && !in_array($key, $this->internal_columns)) {
                $this->errors[] = "Column name '{$key}' for item '{$row['name']}' is not allowed";
                return;
            }
        }
        $this->survey[] = $row;
    }

    public function readItemTableFile($filepath) {
        ini_set('max_execution_time', 360);

        $this->errors = $this->messages = $this->warnings = array();
        if (!file_exists($filepath)) {
            $this->errors[] = 'Item table file does not exist';
            return;
        }

        try {
            //  Identify the type of $filepath and create \PhpOffice\PhpSpreadsheet\Spreadsheet object from a read-only reader
            $filetype = \PhpOffice\PhpSpreadsheet\IOFactory::identify($filepath);
            $phpSpreadsheetReader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($filetype);
            $phpSpreadsheetReader->setReadDataOnly(true);

            /* @var $phpSpreadsheet \PhpOffice\PhpSpreadsheet\Spreadsheet */
            $phpSpreadsheet = $phpSpreadsheetReader->load($filepath);

            // Gather sheets to be read
            if ($phpSpreadsheet->sheetNameExists('survey')) {
                $surveySheet = $phpSpreadsheet->getSheetByName('survey');
            } else {
                $surveySheet = $phpSpreadsheet->getSheet(0);
            }

            if ($phpSpreadsheet->sheetNameExists('choices') && $phpSpreadsheet->getSheetCount() > 1) {
                $choicesSheet = $phpSpreadsheet->getSheetByName('choices');
            } elseif ($phpSpreadsheet->getSheetCount() > 1) {
                $choicesSheet = $phpSpreadsheet->getSheet(1);
            }

            if (isset($choicesSheet)) {
                $this->readChoicesSheet($choicesSheet);
            }

            $this->readSurveySheet($surveySheet);
        } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
            $this->errors[] = "An error occured reading your excel file. Please check your file or report to admin";
            $this->errors[] = $e->getMessage();
            formr_log_exception($e, __CLASS__, $filepath);
            return;
        }
    }

    private function readChoicesSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet) {
        //  Get worksheet dimensions
        // non-allowed columns will be ignored, allows to specify auxiliary information if needed
        $skippedColumns = $columns = array();
        $lastListName = null;
        $colCount = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($worksheet->getHighestDataColumn());
        $rowCount = $worksheet->getHighestDataRow();

        for ($i = 1; $i <= $colCount; $i++) {
            $cellCoordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i) . '1';
            $colName = mb_strtolower((string)$worksheet->getCell($cellCoordinate)->getValue());

            if (in_array($colName, $this->choices_columns)) {
                $columns[$i] = $colName;
            } else {
                $skippedColumns[$i] = $colName;
            }
        }

        if (!in_array('list_name', $columns)) {
            $this->errors[] = 'You forgot to define the "list_name" column on the choices sheet.';
        }
        if (!in_array('name', $columns)) {
            $this->errors[] = 'You forgot to define the "name" column on the choices sheet';
        }
        if (!in_array('label', $columns)) {
            $this->errors[] = 'You forgot to define the "label" column on the choices sheet.';
        }

        if ($this->errors) {
            return false;
        }

        if ($skippedColumns) {
            $this->warnings[] = sprintf('Choices worksheet "%s" <strong>skipped</strong> columns: %s', $worksheet->getTitle(), implode(", ", $skippedColumns));
        }
        $this->messages[] = sprintf('Choices worksheet "%s" <strong>used</strong> columns: %s', $worksheet->getTitle(), implode(", ", $columns));

        $data = array();
        $choiceNames = array();
        $inheritedListNames = array();

        foreach ($worksheet->getRowIterator(1, $rowCount) as $row) {
            /* @var $row \PhpOffice\PhpSpreadsheet\Worksheet\Row */
            $rowNumber = $row->getRowIndex();
            if ($rowNumber == 1) {
                // skip table head
                continue;
            }
            if ($rowNumber > $rowCount)
                break;

            $data[$rowNumber] = array();
            $cellIterator = $row->getCellIterator('A', $worksheet->getHighestDataColumn());
            $cellIterator->setIterateOnlyExistingCells(false);
            foreach ($cellIterator as $cell) {
                /* @var $cell \PhpOffice\PhpSpreadsheet\Cell\Cell */
                if (is_null($cell)) {
                    continue;
                }

                $colNumber = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($cell->getColumn());
                if (!isset($columns[$colNumber])) {
                    continue; // not a column of interest
                }
                $colName = $columns[$colNumber];
                $cellValue = hardTrueFalse(Normalizer::normalize((string)$cell->getValue(), Normalizer::FORM_C));
                $cellValue = trim($cellValue);

                if ($colName == 'list_name') {
                    if ($cellValue && !preg_match("/^[a-zA-Z0-9_]{1,255}$/", $cellValue)) {
                        $this->errors[] = __("The list name '%s' is invalid. It has to be between 1 and 255 characters long. It may not contain anything other than the characters from <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the underscore.", $cellValue);
                    }

                    if (!$cellValue && !isset($lastListName)) {
                        $this->warnings[] = __('Skipping Row %s of choices sheet', $rowNumber);
                        unset($data[$rowNumber]);
                        continue 2;
                    } elseif (!$cellValue && isset($lastListName)) {
                        $cellValue = $lastListName;
                        if (!isset($inheritedListNames[$cellValue])) {
                            $inheritedListNames[$cellValue] = array();
                        }
                        $inheritedListNames[$cellValue][] = $rowNumber;
                    }

                    if (!in_array($cellValue, $this->existing_choice_lists)) {
                        $this->existing_choice_lists[] = $cellValue;
                        $data[$rowNumber]['list_name'] = $cellValue;
                    } elseif (in_array($cellValue, $this->existing_choice_lists) && $lastListName != $cellValue) {
                        $this->errors[] = __("We found a discontinuous list: the same list name ('<em>%s</em>') was used before row %s, but other lists came in between.", $cellValue, $rowNumber);
                    } else {
                        //$data[$rowNumber]['list_name'] = $cellValue;
                    }

                    $lastListName = $cellValue;
                } elseif ($colName == 'name') {
                    if (!is_formr_truthy($cellValue)) {
                        $this->warnings[] = __("Skipping Row %s of choices sheet: Choice name empty, but content in other columns.", $rowNumber);
                        if (isset($inheritedListNames[$data[$rowNumber]['list_name']])) {
                            // remove this row from bookmarks
                            $bmk = &$inheritedListNames[$data[$rowNumber]['list_name']];
                            if (($key = array_search($rowNumber, $bmk)) !== false) {
                                unset($bmk[$key]);
                            }
                        }
                        unset($data[$rowNumber]);
                        continue 2;
                    }
                    if (!preg_match("/^.{1,255}$/", $cellValue)) {
                        $this->errors[] = __("The choice name '%s' is invalid. It has to be between 1 and 255 characters long.", $cellValue);
                    }
                    //$data[$rowNumber]['name'] = $cellValue;
                } elseif ($colName == 'label') {
                    if (!$cellValue && isset($data[$rowNumber]['name'])) {
                        $cellValue = $data[$rowNumber]['name'];
                    }
                    //$data[$rowNumber]['label'] = $cellValue;
                }

                // Stop processing if we have any errors
                if ($this->errors) {
                    $error = sprintf("Error in cell %s%s (Choices sheet): \n %s", $cell->getColumn(), $rowNumber, implode("\n", $this->errors));
                    throw new \PhpOffice\PhpSpreadsheet\Exception($error);
                }

                // Save cell value
                $data[$rowNumber][$colName] = $cellValue;
            } // Cell loop
        } // Rows loop
        // Data has been gathered, group lists by list_name and check if there are duplicates for each list.
        foreach ($data as $rowNumber => $row) {
            if (!isset($choiceNames[$row['list_name']])) {
                $choiceNames[$row['list_name']] = array();
            }
            if (isset($choiceNames[$row['list_name']][$row['name']])) {
                throw new \PhpOffice\PhpSpreadsheet\Exception(sprintf("'%s' has already been used as a 'name' for the list '%s'", $row['name'], $row['list_name']));
            }
            $choiceNames[$row['list_name']][$row['name']] = $row['label'];
        }

        // Announce rows that inherited list_names
        $msgs = array();
        foreach ($inheritedListNames as $name => $rows) {
            $msgs[] = $rows ? sprintf("%s: This list name was assigned to rows %s - %s automatically, because they had an empty list name and followed in this list.", $name, min($rows), max($rows)) : null;
        }
        if ($msgs = array_filter($msgs)) {
            $this->messages[] = '<ul><li>' . implode('</li><li>', $msgs) . '</li></ul>';
        }

        $this->choices = $data;
    }

    private function readSurveySheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet) {
        $callStartTime = microtime(true);
        // non-allowed columns will be ignored, allows to specify auxiliary information if needed

        $skippedColumns = $columns = array();
        $colCount = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($worksheet->getHighestDataColumn());
        $rowCount = $worksheet->getHighestDataRow();

        if ($colCount > 30) {
            $this->warnings[] = __('Only the first 30 columns out of %d were read.', $colCount);
            $colCount = 30;
        }

        $blankColCount = 0; // why should this be set to 1 by default? 
        for ($i = 1; $i <= $colCount; $i++) {
            $cellCoordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i) . '1';
            $colName = mb_strtolower((string)$worksheet->getCell($cellCoordinate)->getValue());

            if (!$colName) {
                $blankColCount++;
                continue;
            }
            if (in_array($colName, $this->survey_columns)) {
                $columns[$i] = $colName;
            } else {
                $skippedColumns[$i] = $colName;
            }

            if ($colName == 'choice1' && (!in_array('name', $columns) || !in_array('type', $columns))) {
                $this->errors[] = "The 'name' and 'type' column have to be placed to the left of all choice columns.";
                return false;
            }
        }

        if ($blankColCount) {
            $this->warnings[] = __('Your survey sheet appears to contain %d columns without names (given in the first row).', $blankColCount);
        }
        if ($skippedColumns) {
            $this->warnings[] = __('These survey sheet columns were <strong>skipped</strong>: %s', implode(', ', $skippedColumns));
        }

        $data = $skippedRows = $emptyRows = $variableNames = array();

        foreach ($worksheet->getRowIterator(1, $rowCount) as $row) {
            /* @var $row \PhpOffice\PhpSpreadsheet\Worksheet\Row */
            $rowNumber = $row->getRowIndex();
            if ($rowNumber == 1) {
                // skip table head
                continue;
            }
            if ($rowNumber > $rowCount)
                break;

            $data[$rowNumber] = array();
            $cellIterator = $row->getCellIterator('A', $worksheet->getHighestDataColumn());
            $cellIterator->setIterateOnlyExistingCells(false);

            foreach ($cellIterator as $cell) {
                /* @var $cell \PhpOffice\PhpSpreadsheet\Cell\Cell */
                if (is_null($cell)) {
                    continue;
                }

                $colNumber = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($cell->getColumn());
                if (!isset($columns[$colNumber])) {
                    continue; // not a column of interest
                }
                $colName = $columns[$colNumber];
                if (isset($data[$rowNumber][$colName])) {
                    continue; // dont overwrite set columns
                }

                $cellValue = trim(hardTrueFalse(Normalizer::normalize((string)$cell->getValue(), Normalizer::FORM_C)));

                if ($colName == 'name') {
                    if (!$cellValue) {
                        if (!empty($data[$rowNumber])) {
                            $skippedRows[] = $rowNumber;
                        } else {
                            $emptyRows[] = $rowNumber;
                        }
                        unset($data[$rowNumber]);
                        continue 2; // Skip row with no item name
                    } elseif (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,64}$/', $cellValue)) {
                        $this->errors[] = __("The variable name '%s' is invalid. It has to be between 1 and 64 characters. It needs to start with a letter and can only contain the characters from <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the underscore.", $cellValue);
                    }

                    if (in_array($cellValue, array('session_id', 'created', 'modified', 'ended', 'id', 'study_id', 'iteration'))) {
                        $this->errors[] = __("Row %s: variable name '%s' is not permitted.", $rowNumber, $cellValue);
                    }

                    if (($existingRow = array_search(mb_strtolower($cellValue), $variableNames)) === false) {
                        $variableNames[$rowNumber] = mb_strtolower($cellValue);
                    } else {
                        $this->errors[] = __("Row %s: Variable name '%s' already appeared in row %s", $rowNumber, $cellValue, $existingRow);
                    }
                } elseif ($colName == 'type') {
                    if (mb_strpos($cellValue, ' ') !== false) {
                        $typeOptions = explode(' ', trim(preg_replace('/\s+/', ' ', $cellValue))); // get real type and options
                        $type = $typeOptions[0];
                        unset($typeOptions[0]);
                        if (!empty($typeOptions[1]) &&
                                !in_array($type, array('server', 'get', 'text', 'textarea', 'letters', 'file', 'image', 'rating_button', 'submit')) &&
                                preg_match('/^[A-Za-z0-9_]{1,20}$/', trim($typeOptions[1]))) {
                            $data[$rowNumber]['choice_list'] = trim($typeOptions[1]);
                            unset($typeOptions[1]);
                        }
                        $data[$rowNumber]['type_options'] = implode(' ', $typeOptions);
                        $cellValue = $type;
                    }

                    $cellValue = $cellValue;
                } elseif ($colName == 'optional') {
                    if ($cellValue === '*') {
                        $cellValue = 1;
                    } elseif ($cellValue === '!') {
                        $cellValue = 0;
                    } else {
                        $cellValue = null;
                    }
                } elseif (strpos($colName, 'choice') === 0 && is_formr_truthy($cellValue) && isset($data[$rowNumber])) {
                    $choiceValue = substr($colName, 6);
                    $this->choices[] = array(
                        'list_name' => $data[$rowNumber]['name'],
                        'name' => $choiceValue,
                        'label' => $cellValue,
                    );

                    if (!isset($data[$rowNumber]['choice_list'])) {
                        $data[$rowNumber]['choice_list'] = $data[$rowNumber]['name'];
                    } elseif (isset($data[$rowNumber]['choice_list']) && $choiceValue == 1) {
                        $this->errors[] = __("Row %s: You defined both a named choice_list '%s' for item '%s' and a nonempty choice1 column. Choose one.", $rowNumber, $data[$rowNumber]['choice_list'], $data[$rowNumber]['name']);
                    }
                }

                // Stop processing if we have any errors
                if ($this->errors) {
                    $error = sprintf("Error in cell %s%s (Survey Sheet): \n %s", $cell->getColumn(), $rowNumber, implode("\n", $this->errors));
                    throw new \PhpOffice\PhpSpreadsheet\Exception($error);
                }

                // Save cell value
                $data[$rowNumber][$colName] = $cellValue;
            } // Cell Loop

            $data[$rowNumber]['order'] = $rowNumber - 1;
            // if no order is entered, use row_number
            if (!isset($data[$rowNumber]['item_order']) || !is_formr_truthy($data[$rowNumber]['item_order'])) {
                $data[$rowNumber]['item_order'] = $data[$rowNumber]['order'];
            }
        } // Rows Loop

        $callEndTime = microtime(true);
        $callTime = $callEndTime - $callStartTime;
        $this->messages[] = 'Survey <abbr title="Call time to read survey sheet was ' . sprintf('%.4f', $callTime) . ' seconds">worksheet</abbr> - ' . $worksheet->getTitle() . ' (' . count($data) . ' non-empty rows, ' . $colCount . ' columns). These columns were <strong>used</strong>: ' . implode(", ", $columns);

        if (!empty($emptyRows)) {
            $this->messages[] = __('Empty rows (no variable name): %s', implode(", ", $emptyRows));
        }

        if (!empty($skippedRows)) {
            $this->warnings[] = __('Skipped rows (no variable name): %s. Variable name empty, but other columns had content. Double-check that you did not forget to define a variable name for a proper item.', implode(", ", $skippedRows));
        }

        $this->survey = $data;
    }

}
