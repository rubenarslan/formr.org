<?php

class SpreadsheetReader {

	private $choices_columns = array('list_name', 'name', 'label');
	private $survey_columns = array('name', 'type', 'label', 'optional', 'class', 'showif', 'choice1', 'choice2', 'choice3', 'choice4', 'choice5', 'choice6', 'choice7', 'choice8', 'choice9', 'choice10', 'choice11', 'choice12', 'choice13', 'choice14', 'value', 'order', 'block_order', 'item_order',
		# legacy
		'variablenname', 'wortlaut', 'typ', 'ratinguntererpol', 'ratingobererpol', 'mcalt1', 'mcalt2', 'mcalt3', 'mcalt4', 'mcalt5', 'mcalt6', 'mcalt7', 'mcalt8', 'mcalt9', 'mcalt10', 'mcalt11', 'mcalt12', 'mcalt13', 'mcalt14',);
	private $internal_columns = array('choice_list', 'type_options', 'label_parsed');
	public $messages = array();
	public $errors = array();
	public $warnings = array();
	public $survey = array();
	public $choices = array();
	public $exportFormats = array('csv', 'csv_german', 'tsv', 'xlsx', 'xls', 'json');

	public function backupTSV($array, $filename) {
		$objPHPExcel = $this->objectFromArray($array);

		$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'CSV');
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
		ini_set('memory_limit', '1024M');

		$objPHPExcel = new PHPExcel();
		$current = current($array);
		if (!$current) {
			return $objPHPExcel;
		}
		array_unshift($array, array_keys($current));
		$objPHPExcel->getSheet(0)->fromArray($array);

		return $objPHPExcel;
	}

	public function exportCSV($array, $filename) {
		$objPHPExcel = $this->objectFromArray($array);

		$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'CSV');

		header('Content-Disposition: attachment;filename="' . $filename . '.csv"');
		header('Cache-Control: max-age=0');
		header('Content-type: text/csv');

		try {
			$objWriter->save('php://output');
			exit;
		} catch (Exception $e) {
			formr_log_exception($e, __CLASS__);
			alert("Couldn't save file.", 'alert-danger');
			return false;
		}
	}

	public function exportJSON($array, $filename) {
		set_time_limit(300); # defaults to 30
		ini_set('memory_limit', '1024M');

		header('Content-Disposition: attachment;filename="' . $filename . '.json"');
		header('Cache-Control: max-age=0');
		header('Content-type: application/json');

		try {
			echo json_encode($array, JSON_PRETTY_PRINT + JSON_UNESCAPED_UNICODE + JSON_NUMERIC_CHECK);
			exit;
		} catch (Exception $e) {
			formr_log_exception($e, __CLASS__);
			alert("Couldn't save file.", 'alert-danger');
			return false;
		}
	}

	public function exportTSV($array, $filename) {
		$objPHPExcel = $this->objectFromArray($array);

		$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'CSV');
		$objWriter->setDelimiter("\t");
		$objWriter->setEnclosure("");

		header('Content-Disposition: attachment;filename="' . $filename . '.tab"');
		header('Cache-Control: max-age=0');
		header('Content-type: text/csv'); // or maybe text/tab-separated-values?

		try {
			$objWriter->save('php://output');
			exit;
		} catch (Exception $e) {
			formr_log_exception($e, __CLASS__);
			alert("Couldn't save file.", 'alert-danger');
			return false;
		}
	}

	public function exportCSV_german($array, $filename) {
		$objPHPExcel = $this->objectFromArray($array);

		$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'CSV');
		$objWriter->setDelimiter(";");
		$objWriter->setEnclosure('"');

		header('Content-Disposition: attachment;filename="' . $filename . '.csv"');
		header('Cache-Control: max-age=0');
		header('Content-type: text/csv');

		try {
			$objWriter->save('php://output');
			exit;
		} catch (Exception $e) {
			alert("Couldn't save file.", 'alert-danger');
			return false;
		}
	}

	public function exportXLS($array, $filename) {
		$objPHPExcel = $this->objectFromArray($array);

		$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel5');

		header('Content-Disposition: attachment;filename="' . $filename . '.xls"');
		header('Cache-Control: max-age=0');
		header('Content-Type: application/vnd.ms-excel');

		try {
			$objWriter->save('php://output');
			exit;
		} catch (Exception $e) {
			formr_log_exception($e, __CLASS__);

			alert("Couldn't save file.", 'alert-danger');
			return false;
		}
	}

	public function exportXLSX($array, $filename) {
		$objPHPExcel = $this->objectFromArray($array);

		$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel2007');

		header('Content-Disposition: attachment;filename="' . $filename . '.xlsx"');
		header('Cache-Control: max-age=0');
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

		try {
			$objWriter->save('php://output');
			exit;
		} catch (Exception $e) {
			formr_log_exception($e, __CLASS__);
			alert("Couldn't save file.", 'alert-danger');
			return false;
		}
	}

	public function getAllowedColumnNames() {
		return $this->survey_columns;
	}

	private function getSheetsFromArrays($items, $choices = array(), $settings = array()) {
		set_time_limit(300); # defaults to 30
		ini_set('memory_limit', '1024M');

		$objPHPExcel = new PHPExcel();
		$objPHPExcel->getDefaultStyle()->getFont()->setName('Helvetica');
		$objPHPExcel->getDefaultStyle()->getFont()->setSize(16);
		$objPHPExcel->getDefaultStyle()->getAlignment()->setWrapText(true);
		$sheet_index = $objPHPExcel->getSheetCount() - 1;

		if (is_array($choices) && count($choices) > 0):
			$objPHPExcel->createSheet();
			$sheet_index++;
			array_unshift($choices, array_keys(current($choices)));
			$objPHPExcel->getSheet($sheet_index)->getDefaultColumnDimension()->setWidth(20);
			$objPHPExcel->getSheet($sheet_index)->getColumnDimension('A')->setWidth(20); # list_name
			$objPHPExcel->getSheet($sheet_index)->getColumnDimension('B')->setWidth(20); # name
			$objPHPExcel->getSheet($sheet_index)->getColumnDimension('C')->setWidth(30); # label

			$objPHPExcel->getSheet($sheet_index)->fromArray($choices);
			$objPHPExcel->getSheet($sheet_index)->setTitle('choices');
			$objPHPExcel->getSheet($sheet_index)->getStyle('A1:C1')->applyFromArray(array('font' => array('bold' => true)));
		endif;

		if (is_array($settings) && count($settings) > 0):
			// put settings in a suitable format for excel sheet
			$sttgs = array(array('item', 'value'));
			foreach ($settings as $item => $value) {
				$sttgs[] = array('item' => $item, 'value' => (string)$value);
			}

			$objPHPExcel->createSheet();
			$sheet_index++;
			$objPHPExcel->getSheet($sheet_index)->getDefaultColumnDimension()->setWidth(20);
			$objPHPExcel->getSheet($sheet_index)->getColumnDimension('A')->setWidth(20); # item
			$objPHPExcel->getSheet($sheet_index)->getColumnDimension('B')->setWidth(20); # value

			$objPHPExcel->getSheet($sheet_index)->fromArray($sttgs);
			$objPHPExcel->getSheet($sheet_index)->setTitle('settings');
			$objPHPExcel->getSheet($sheet_index)->getStyle('A1:C1')->applyFromArray(array('font' => array('bold' => true)));
		endif;

		array_unshift($items, array_keys(current($items)));
		$objPHPExcel->getSheet(0)->getColumnDimension('A')->setWidth(20); # type
		$objPHPExcel->getSheet(0)->getColumnDimension('B')->setWidth(20); # name
		$objPHPExcel->getSheet(0)->getColumnDimension('C')->setWidth(30); # label
		$objPHPExcel->getSheet(0)->getColumnDimension('D')->setWidth(3);  # optional
		$objPHPExcel->getSheet(0)->getStyle('D1')->getAlignment()->setWrapText(false);

		$objPHPExcel->getSheet(0)->fromArray($items);
		$objPHPExcel->getSheet(0)->setTitle('survey');
		$objPHPExcel->getSheet(0)->getStyle('A1:H1')->applyFromArray(array('font' => array('bold' => true)));


		return $objPHPExcel;
	}

	public function exportItemTableXLSX(Survey $study) {
		$items = $study->getItemsForSheet();
		$choices = $study->getChoicesForSheet();
		$filename = $study->name;

		try {
			$objPHPExcel = $this->getSheetsFromArrays($items, $choices, $study->settings);
			$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel2007');

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

	public function exportItemTableXLS(Survey $study) {
		$items = $study->getItemsForSheet();
		$choices = $study->getChoicesForSheet();
		$filename = $study->name;

		try {
			$objPHPExcel = $this->getSheetsFromArrays($items, $choices, $study->settings);

			$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel5');

			header('Content-Disposition: attachment;filename="' . $filename . '.xls"');
			header('Cache-Control: max-age=0');
			header('Content-Type: application/vnd.ms-excel');
			$objWriter->save('php://output');
			exit;
		} catch (Exception $e) {
			formr_log_exception($e, __CLASS__);
			alert("Couldn't save file.", 'alert-danger');
			return false;
		}
	}

	public function exportItemTableJSON(Survey $study, $return_object = false) {
		$items = $study->getItems();
		$choices = $study->getChoices();
		$filename = $study->name;

		foreach ($items AS $i => $val) {
			unset($items[$i]['id'], $items[$i]['study_id']);
			if (isset($val["choice_list"]) && isset($choices[$val["choice_list"]])) {
				$items[$i]["choices"] = $choices[$val["choice_list"]];
				unset($val["choice_list"]);
			}
		}

		$object = array(
			'name' => $study->name,
			'items' => $items,
			'settings' => $study->settings,
		);

		if ($return_object === true) {
			return $object;
		}

		header('Content-Disposition: attachment;filename="' . $filename . '.json"');
		header('Cache-Control: max-age=0');
		header('Content-type: application/json');

		try {
			echo json_encode($object, JSON_PRETTY_PRINT + JSON_UNESCAPED_UNICODE + JSON_NUMERIC_CHECK);
			exit;
		} catch (Exception $e) {
			formr_log_exception($e, __CLASS__);
			alert("Couldn't save file.", 'alert-danger');
			return false;
		}
	}

	private function translate_legacy_column($col) {
		$col = trim(mb_strtolower($col));
		if ($col == 'variablenname')
			$col = 'name';
		elseif ($col == 'typ')
			$col = 'type';
		elseif ($col == 'wortlaut' or $col == 'text')
			$col = 'label';
		elseif (mb_substr($col, 0, 5) == 'mcalt')
			$col = 'choice' . mb_substr($col, 5);
		elseif ($col == 'ratinguntererpol')
			$col = 'choice1';
		elseif ($col == 'ratingobererpol')
			$col = 'choice2';

		return $col;
	}

	private function translate_legacy_type($type) {
		$type = trim(mb_strtolower($type));

		if ($type == 'offen')
			$type = 'text';
		elseif ($type == 'instruktion')
			$type = 'note';
		elseif ($type == 'instruction')
			$type = 'note';
		elseif ($type == 'fork')
			$type = 'note';
		elseif ($type == 'rating')
			$type = 'rating_button';
		elseif ($type == 'mmc')
			$type = 'mc_multiple';
		elseif ($type == 'select')
			$type = 'select_one';
		elseif ($type == 'mselect')
			$type = 'select_multiple';
		elseif ($type == 'select_add')
			$type = 'select_or_add_one';
		elseif ($type == 'mselect_add')
			$type = 'select_or_add_multiple';
		elseif ($type == 'btnrating')
			$type = 'rating_button';
		elseif ($type == 'range_list')
			$type = 'range_ticks';
		elseif ($type == 'btnradio')
			$type = 'mc_button';
		elseif ($type == 'btncheckbox')
			$type = 'mc_multiple_button';
		elseif ($type == 'btncheck')
			$type = 'check_button';
		elseif ($type == 'geolocation')
			$type = 'geopoint';
		elseif ($type == 'mcnt')
			$type = 'mc';

		return $type;
	}

	public function readItemTableFile($inputFileName) {
		$this->errors = $this->messages = array();

		define('EOL', (PHP_SAPI == 'cli') ? PHP_EOL : '<br />');

		if (!file_exists($inputFileName)):
			exit($inputFileName . " does not exist." . EOL);
		endif;

		try {
			//  Identify the type of $inputFileName 
			$inputFileType = PHPExcel_IOFactory::identify($inputFileName);
			//  Create a new Reader of the type that has been identified 
			$objReader = PHPExcel_IOFactory::createReader($inputFileType);
			//  Load $inputFileName to a PHPExcel Object 
			///  Advise the Reader that we only want to load cell data 
			$objReader->setReadDataOnly(true);

			// Load $inputFileName to a PHPExcel Object
			$objPHPExcel = PHPExcel_IOFactory::load($inputFileName);
		} catch (PHPExcel_Exception $e) {
			$this->errors[] = "An error occured reading your excel file. Please check your file or report to admin";
			$this->errors[] = $e->getMessage();
			formr_log_exception($e, __CLASS__, $inputFileName);
			return;
		}

		if ($objPHPExcel->sheetNameExists('survey')) {

			$survey_sheet = $objPHPExcel->getSheetByName('survey');
		} else {
			$survey_sheet = $objPHPExcel->getSheet(0);
		}

		if ($objPHPExcel->sheetNameExists('choices') AND $objPHPExcel->getSheetCount() > 1) {
			$choices_sheet = $objPHPExcel->getSheetByName('choices');
		} elseif ($objPHPExcel->getSheetCount() > 1) {
			$choices_sheet = $objPHPExcel->getSheet(1);
		}

		if (isset($choices_sheet)) {
			$this->readChoicesSheet($choices_sheet);
		}

		$this->readSurveySheet($survey_sheet);
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

	private $existing_choice_lists = array();

	private function readChoicesSheet($worksheet) {
		$callStartTime = microtime(true);

		//  Get worksheet dimensions
		// non-allowed columns will be ignored, allows to specify auxiliary information if needed

		$skipped_columns = $columns = array();
		$nr_of_columns = PHPExcel_Cell::columnIndexFromString($worksheet->getHighestColumn());

		for ($i = 0; $i < $nr_of_columns; $i++):
			$col_name = mb_strtolower($worksheet->getCellByColumnAndRow($i, 1)->getValue());
			if (in_array($col_name, $this->choices_columns)):
				$columns[$i] = $col_name;
			elseif ($col_name):
				$skipped_columns[$i] = $col_name;
			endif;
		endfor;
		$this->messages[] = 'Choices worksheet - ' . $worksheet->getTitle();
		$choices_messages[] = 'These columns were <strong>used</strong>: ' . implode($columns, ", ");
		if (!empty($skipped_columns))
			$this->warnings[] = 'These choices sheet columns were <strong>skipped</strong>: ' . implode($skipped_columns, ", ");

		if (count($columns) > 0 AND ! in_array("list_name", $columns)):
			$this->errors[] = "You forgot to define the list_name column on the choices sheet.";
		endif;
		if (count($columns) > 0 AND ! in_array("name", $columns)):
			$this->errors[] = "You forgot to define the name column on the choices sheet.";
		endif;
		if (count($columns) > 0 AND ! in_array("label", $columns)):
			$this->errors[] = "You forgot to define the label column on the choices sheet.";
		endif;


		if (!empty($this->errors)):
			return false;
		endif;
		#	var_dump($columns);

		$data = array();
		$choice_names = array();

		foreach ($worksheet->getRowIterator() AS $row):

			$row_number = $row->getRowIndex();

			if ($row_number == 1): # skip table head
				continue;
			endif;
			$cellIterator = $row->getCellIterator();
			$cellIterator->setIterateOnlyExistingCells(false); // Loop all cells, even if it is not set

			$data[$row_number] = array();

			foreach ($cellIterator AS $cell):
				if (!is_null($cell)):
					$column_number = $cell->columnIndexFromString($cell->getColumn()) - 1;

					if (!array_key_exists($column_number, $columns))
						continue; // skip columns that aren't allowed

					$col = $columns[$column_number];
					$val = hardTrueFalse(Normalizer::normalize($cell->getValue(), Normalizer::FORM_C));


					if ($col == 'list_name'):
						$val = trim($val);

						if ($val == ''):

							if (isset($lastListName)):
								$choices_messages[] = __("Row $row_number: list name empty. The previous list name %s was used.", $lastListName);
								$val = $lastListName;
							else:
								if (isset($data[$row_number])):
									unset($data[$row_number]);
								endif;
								$choices_messages[] = "Row $row_number: list name empty. Skipped.";

								continue 2; # skip this row
							endif;

						elseif (!preg_match("/^[a-zA-Z0-9_]{1,255}$/", $val)):
							$this->errors[] = __("The list name '%s' is invalid. It has to be between 1 and 255 characters long. It may not contain anything other than the characters from <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the underscore.", $val);
						endif;

						if (!in_array($val, $this->existing_choice_lists)):
							$this->existing_choice_lists[] = $val;
							$choice_names[$val] = array(); // of course choices only should be unique in a list
						elseif (in_array($val, $this->existing_choice_lists) AND $val != $lastListName):
							$this->errors[] = __("We found a discontinuous list: the same list name ('<em>%s</em>') was used before row %s, but other lists came in between.", h($val), $row_number);
						endif;

						$lastListName = $val;

					elseif ($col == 'name'):
						$val = trim($val);
						if ($val == ''):
							$choices_messages[] = "Row $row_number: choice name empty. Row skipped.";
							if (isset($data[$row_number])):
								unset($data[$row_number]);
							endif;
							continue 2; # skip this row

						elseif (!preg_match("/^[a-zA-Z0-9_]{1,255}$/", $val)):
							$this->errors[] = __("The choice name '%s' is invalid. It has to be between 1 and 255 characters long. It may not contain anything other than the characters from <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the underscore.", $val);
						endif;

						if (($previous = array_search(mb_strtolower($val), $choice_names[$data[$row_number]['list_name']])) === false):
							$choice_names[$data[$row_number]['list_name']][$row_number] = mb_strtolower($val);
						else:
							$this->errors[] = "Row $row_number: choice name '$val' already appeared in the list of choices, last in row $previous.";
						endif;

					elseif ($col == 'label'):
						if (!trim($val)):
							$val = $data[$row_number]['name'];
						endif;
					endif;


				endif;  // cell null

				$data[$row_number][$col] = $val;

			endforeach; // cell loop

		endforeach; // row loop
//		$callEndTime = microtime(true);
//		$callTime = $callEndTime - $callStartTime;
//		$choices_messages[] = 'Call time to read choices sheet was ' . sprintf('%.4f',$callTime) . " seconds" . EOL .  "$row_number rows were read. Current memory usage: " . (memory_get_usage(true) / 1024 / 1024) . " MB" ;

		$this->messages[] = '<ul><li>' . implode("</li><li>", $choices_messages) . '</li></ul>';
		$this->choices = $data;
	}

	private function readSurveySheet($worksheet) {
		$callStartTime = microtime(true);
		// non-allowed columns will be ignored, allows to specify auxiliary information if needed

		$columns = array();
		$nr_of_columns = PHPExcel_Cell::columnIndexFromString($worksheet->getHighestColumn());
		if ($nr_of_columns > 30) {
			$this->warnings[] = __('Only the first 30 columns out of %d were read.', $nr_of_columns);
			$nr_of_columns = 30;
		}

		$nr_of_blank_column_headers = 0;
		for ($i = 0; $i < $nr_of_columns; $i++):
			$col_name = mb_strtolower($worksheet->getCellByColumnAndRow($i, 1)->getValue());
			if (trim($col_name) != ''):
				if (in_array($col_name, $this->survey_columns)):
					$oldCol = $col_name;
					$col_name = $this->translate_legacy_column($col_name);

					if ($oldCol != $col_name)
						$this->warnings[] = __('The column "<em>%s</em>" is deprecated and was automatically translated to "<em>%s</em>"', $oldCol, $col_name);

					$columns[$i] = $col_name;

				else:
					$skipped_columns[$i] = $col_name;
				endif;
			else:
				$nr_of_blank_column_headers++;
			endif;

			if ($col_name == 'choice1' AND ! array_search('name', $columns)):
				$this->errors[] = 'The name and type column have to be placed to the left of all choice columns.';
				return false;
			endif;
		endfor;
		if ($nr_of_blank_column_headers > 0)
			$this->warnings[] = __('Your sheet appears to contain at least %d columns without names (in the first row)."', $nr_of_blank_column_headers);

		$empty_rows = array();
		$this->messages[] = 'These columns were <strong>used</strong>: ' . implode($columns, ", ");
		if (!empty($skipped_columns)) {
			$this->warnings[] = 'These survey sheet columns were <strong>skipped</strong>: ' . implode($skipped_columns, ", ");
		}

		$variablennames = $data = array();

		foreach ($worksheet->getRowIterator() as $row):
			$row_number = $row->getRowIndex();

			// skip table head
			if ($row_number == 1) {
				continue;
			}

			$cellIterator = $row->getCellIterator();
			$cellIterator->setIterateOnlyExistingCells(false); // Loop all cells, even if it is not set

			foreach ($cellIterator as $cell) :
				if (!is_null($cell)):
					$column_number = $cell->columnIndexFromString($cell->getColumn()) - 1;

					// skip columns that aren't allowed
					if (!array_key_exists($column_number, $columns)) {
						continue;
					}

					$col = $columns[$column_number];
					// dont overwrite set columns
					if (isset($data[$row_number][$col])) {
						continue;
					}

					$val = hardTrueFalse(Normalizer::normalize($cell->getValue(), Normalizer::FORM_C));

					if ($col == 'name'):
						$val = trim($val);
						if ($val == ''):
							$empty_rows[] = $row_number;
							if (isset($data[$row_number])) {
								unset($data[$row_number]);
							}
							// skip this row
							continue 2;

						elseif (!preg_match("/^[a-zA-Z][a-zA-Z0-9_]{1,64}$/", $val)):
							$this->errors[] = __("The variable name '%s' is invalid. It has to be between 1 and 64 characters. It needs to start with a letter and can only contain the characters from <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the underscore.", $val);
						endif;

						if (in_array($val, array('session_id', 'created', 'modified', 'ended'))):
							$this->errors[] = "Row $row_number: variable name '$val' is not permitted.";
						endif;

						if (($previous = array_search(mb_strtolower($val), $variablennames)) === false):
							$variablennames[$row_number] = mb_strtolower($val);
						else:
							$this->errors[] = "Row $row_number: variable name '$val' already appeared, last in row $previous.";
						endif;

					elseif ($col == 'type'):
						if (mb_strpos($val, ' ') !== false):
							$val = preg_replace('/\s+/', ' ', $val);
							$type_options = explode(' ', trim($val), 2); // get real type and options
							$val = $type_options[0];
							unset($type_options[0]); // remove real type from options
							//todo: find all items where the "you defined choices message" error might erroneously be triggered
							if (isset($type_options[1]) && !in_array($val, array('server', 'get', 'text', 'textarea', 'file', 'image', 'rating_button', 'submit')) && preg_match('/^[A-Za-z0-9_]{1,20}$/', trim($type_options[1]))):
								$data[$row_number]['choice_list'] = $type_options[1];
								unset($type_options[1]);
							endif;

							$data[$row_number]['type_options'] = implode(' ', $type_options);
						endif;

						$oldType = $val;
						$val = $this->translate_legacy_type($val);

						if ($oldType != $val):
							$this->warnings[] = __('The type "<em>%s</em>" is deprecated and was automatically translated to "<em>%s</em>"', $oldType, $val);
						endif;

					elseif ($col == 'optional'):
						if ($val === '*')
							$val = 1;
						elseif ($val === '!')
							$val = 0;
						else
							$val = null;
					elseif (mb_strpos($col, "choice") === 0 AND ( $val !== null AND $val !== '')):

						$nr = mb_substr($col, 6);
						$this->choices[] = array(
							'list_name' => $data[$row_number]['name'],
							'name' => $nr,
							'label' => $val,
						);

						if (!isset($data[$row_number]['choice_list'])):

							$data[$row_number]['choice_list'] = $data[$row_number]['name'];

						elseif (isset($data[$row_number]['choice_list']) AND $nr == 1):

							$this->errors[] = __("Row $row_number: You defined both a named choice_list '%s' for item '%s' and a nonempty choice1 column. Choose one.", $data[$row_number]['choice_list'], $data[$row_number]['name']);

						endif;

					endif; // cell null

					$data[$row_number][$col] = $val;
				endif; // validation

			endforeach; // cell loop

			$data[$row_number]["order"] = $row_number - 1;
			// if no order is entered, use row_number
			if(!isset($data[$row_number]["item_order"]) || trim($data[$row_number]["item_order"]) === "") {
				$data[$row_number]["item_order"] = $row_number - 1;
			}
			// row has been put into array
#			if(!isset($data[$row_number]['id'])) $data[$row_number]['id'] = $row_number;

		endforeach; // row loop


		$callEndTime = microtime(true);
		$callTime = $callEndTime - $callStartTime;
		$this->messages[] = 'Survey <abbr title="Call time to read survey sheet was ' . sprintf('%.4f', $callTime) . ' seconds">worksheet</abbr> - ' . $worksheet->getTitle() . ' (' . $row_number . ' rows, ' . $nr_of_columns . ' columns)';
		if (!empty($empty_rows)) {
			$this->messages[] = "Rows " . implode($empty_rows, ", ") . ": variable name empty. Rows skipped.";
		}

		$this->survey = $data;
	}

}
