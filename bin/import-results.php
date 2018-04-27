#!/usr/bin/php
<?php

/**
 * Manual import of formr exported results
 * Export file must be in CSV format
 * 
 */

require_once dirname(__FILE__) . '/../setup.php';

function quoteCols($db, $cols) {
	foreach ($cols as $i => $col) {
		$cols[$i] = DB::quoteCol($col);
	}
	return $cols;
}

function quoteVals($db, $vals) {
	foreach ($vals as $i => $val) {
		$vals[$i] = $db->quote($val);
	}
	return $vals;
}

function help() {
	echo "
		Import results of a study into a run. Results should referably be in CSV format.
		This script generates an SQL file that can then be ran against the respective formr database.
		Usage:
			php ./import-results.php [options]
			Example: php ./import-results.php --survey-id=<sid> --run-id=<rid> --backup-file=<file-path>
				 php ./import-results.php --survey-id=87 --run-id=29 --backup-file=\"/var/www/formr.org/tmp/backups/results/xxxxx.xlsx\"
		Options:
			-h: Prints this help message
			--survey-id  : Numeric ID of the survey to which you want to import data
			--run-id     : Numeric ID of the run to which you want to import data
			--position   : Position of the UNIT ID in the run
			--backup-file: Asolute path to the backup results file
			--include-itemsdisplay: If this flag is present then queries for survey_items_display table will be included
			
	";
	exit(0);
}

function quit($msg = '', $code = 1) {
	if ($code > 0) {
		$msg = "Error($code): $msg";
	}
	echo "\n$msg\n";
	exit($code);
}

function collectVars() {
	$opts = getopt ('h', array('survey-id:', 'run-id:', 'backup-file:', 'include-itemsdisplay', 'help'));
	if (isset($opts['h']) || isset($opts['help'])) {
		return help();
	}

	if (!isset($opts['survey-id'])) {
		quit("Missing option 'survey-id'");
	}

	if (!isset($opts['run-id'])) {
		quit("Missing option 'run-id'");
	}

	if (!isset($opts['position'])) {
		quit("You need to specify the position of the Run unit to which the survey is attached");
	}

	if (!isset($opts['backup-file'])) {
		quit("Missing option 'backup-file'");
	}
	$backupFile = trim($opts['backup-file']);
	if (!file_exists($backupFile)) {
		quit("The backup-file provided does not exist");
	}

	return array(
		'runId' => (int) $opts['run-id'],
		'studyId' => (int) $opts['survey-id'],
		'position' => (int) $opts['position'],
		'backupFile' => $backupFile,
		'sqlBackupFile' => $backupFile . '.sql',
		'inlcudeItemsDisplay' => isset($opts['include-itemsdisplay']),
	);
}

function itemsDisplayCols($item_id, $session_id, $created, $answer) {
	if (!$item_id || !$session_id || !$created) {
		return null;
	}

	// Simulate shown and answered times from created time
	$shown = $created;
	$answered = strtotime('+5 minutes', $created);
	$saved = strtotime('+5 minutes', $answered);
	
	return array(
		'item_id' => $item_id,
		'session_id' => $session_id,
		'answer' => $answer,
		'created' => mysql_datetime($created),
		'saved' => mysql_datetime($saved),
		'shown' => mysql_datetime($shown),
		'shown_relative' => NULL,
		'answered' => mysql_datetime($answered),
		'answered_relative' => NULL,
		'displaycount' => 1,
		'display_order' => NULL, // FIX ME
		'hidden' => 0, // FIX-ME
	);
}

$vars = collectVars();
extract($vars);
$sysColumns = array('session', 'session_id', 'study_id', 'created', 'modified', 'ended', 'expired');
$dateColumns = array('created', 'modified', 'ended', 'expired');
$exclColumns = array('session');
$db = DB::getInstance();

$runName = $db->findValue('survey_runs', array('id' => $runId), 'name');
$run = new Run($db, $runName);
if (!$run->valid) {
	quit('Invalid run. ID: ' . $runId);
}
$survey = Survey::loadById($studyId);
if (!$survey->name || !$survey->id) {
	quit('Invalid survey. ID: ' . $studyId);
}

$surveyRunUnit = $db->findRow('survey_run_units', array('unit_id' => $studyId, 'run_id' => $runId));
if (!$surveyRunUnit) {
	quit("The specified survey is not referenced in the run. Please check your study if the run '{$run->name}' has a survey unit with value '{$survey->name}'");
}

$surveyItems = array();
foreach($survey->getItems('id, name') as $item) {
	$surveyItems[$item['name']] = $item;
}

// OPEN results sheet
try {
	echo "\nReading backup file '", $backupFile, "' ...\n";
	//  Identify the type of $inputFileName 
	$fileType = PHPExcel_IOFactory::identify($backupFile);
	//  Create a new Reader of the type that has been identified 
	$objReader = PHPExcel_IOFactory::createReader($fileType);
	//  Load $inputFileName to a PHPExcel Object 
	///  Advise the Reader that we only want to load cell data 
	$objReader->setReadDataOnly(true);

	// Load $inputFileName to a PHPExcel Object
	$objPHPExcel = PHPExcel_IOFactory::load($backupFile);

	// Get sheet
	$resultsSheet = $objPHPExcel->getSheet(0);
} catch (PHPExcel_Exception $e) {
	formr_log_exception($e, __CLASS__, $backupFile);
	quit("Error occured while loading backup file: \n" . $e->getMessage());
}

// Read columns
$columns = array();
$nrColumns = PHPExcel_Cell::columnIndexFromString($resultsSheet->getHighestDataColumn());
$nrRows = $resultsSheet->getHighestDataRow();
echo "\nColumns: {$nrColumns}, Rows: {$nrRows} \n";
for ($i = 0; $i < $nrColumns; $i++) {
	$columns[] = trim($resultsSheet->getCellByColumnAndRow($i, 1)->getValue());
}

// Validate columns of backup sheet to ensure all are in survey items sheet
$badCols = array();
foreach ($columns as $col) {
	if (!isset($surveyItems[$col]) && !in_array($col, $sysColumns)) {
		$badCols[] = $col;
	}
}
if ($badCols) {
	quit('Invalid Column(s) in results sheet: ' . implode(', ', $badCols));
}

// Empty sql backup file
if (file_exists($sqlBackupFile)) {
	rename($sqlBackupFile, $sqlBackupFile . '.formrbk');
}
file_put_contents($sqlBackupFile, '');

// Read rows and insert each entry after reading to prvent overload
$processed = 0;
$fp = fopen($sqlBackupFile, 'wb');
if (!$fp) {
	quit('Unable to open sql file for writing');
}

foreach ($resultsSheet->getRowIterator() as $row) {
	$rowNr = $row->getRowIndex();
	// Read only data rows
	if ($rowNr > $nrRows) {
		break;
	}

	// Heading names
	if ($rowNr == 1) {
		continue;
	}
	
	$cellIterator = $row->getCellIterator();
	$cellIterator->setIterateOnlyExistingCells(false); // Loop all cells, even if it is not set
	$runSession = null;
	$entry = array();
	foreach ($cellIterator as $cell) {
		if (is_null($cell)) {
			continue;
		}
		
		$colIndex = $cell->columnIndexFromString($cell->getColumn()) - 1;
		if (!array_key_exists($colIndex, $columns)) {
			continue;
		}
		$column = $columns[$colIndex];
		$value = trim($cell->getValue());
		if (!is_formr_truthy($value)) {
			$value = null;
		} elseif (in_array($column, $dateColumns)) {
			$value = mysql_datetime(PHPExcel_Shared_Date::ExcelToPHP($value));
		}
		
		if (!in_array($column, $exclColumns)) {
			$entry[$column] = $value;
		}
		
		if ($value && $runSession === null && $column == 'session') {
			$value = ltrim($value, '=');
			$runSession = new RunSession($db, $runId, null, $value, $run);
			// Create a fake session and end it if it doesn't exist (maybe set a flag to enable this in command
			if ($runSession->id <= 0) {
				$runSession->create($value);
				$runSession->runTo($position, $studyId);
				$unitSession = $runSession->getCurrentUnit();
				$entry['session_id'] = $unitSession['session_id'];
				$runSession->end();
			} else {
				$unitSession = new UnitSession($db, $runSession->id, $studyId);
				$entry['session_id'] = $unitSession->create();
			}
		}
	}

	if ($entry && $runSession && $runSession->id > 0) {
		$entry['study_id'] = $studyId;
		$newline =  "\n\n/*NEW ROW: Inserting data for run-session: " . $runSession->session . "*/";
		echo $newline;
		fwrite($fp, $newline);

		$unitSession = array(
			'id' => $entry['session_id'],
			'unit_id' => $studyId,
			'run_session_id' => $runSession->id,
			'created' => $entry['created'],
			'ended' => $entry['ended'],
		);

		// Insert unit session entry
		$unitSessionCols = quoteCols($db, array_keys($unitSession));
		$unitSessionVals = quoteVals($db, array_values($unitSession));
		$sql = "\nINSERT INTO `survey_unit_sessions` (" . implode(', ', $unitSessionCols). ") VALUES (" . implode(', ', $unitSessionVals). ") ON DUPLICATE KEY UPDATE id=VALUES(id);";
		fwrite($fp, $sql);
	
		// Insert results table entry
		$resultCols = quoteCols($db, array_keys($entry));
		$resultVals = quoteVals($db, array_values($entry));
		array_walk($resultCols, array('DB', 'quoteCol'));
		array_walk($resultVals, array($db, 'quote'));
		$sql = "\nINSERT INTO `{$survey->results_table}` (" . implode(', ', $resultCols). ") VALUES (" . implode(', ', $resultVals). ") ON DUPLICATE KEY UPDATE session_id=VALUES(session_id);";
		fwrite($fp, $sql);

		// Insert to items display table
		foreach ($entry as $itemName => $itemValue) {
			if (!isset($surveyItems[$itemName])) {
				continue;
			}

			$item_id = array_val($surveyItems[$itemName], 'id');
			$session_id = $entry['session_id'];
			$created = strtotime($entry['created']);
			$itemsDisplay = itemsDisplayCols($item_id, $session_id, $created, $itemValue);

			if (!empty($inlcudeItemsDisplay) && ($itemsDisplay = itemsDisplayCols($item_id, $session_id, $created, $itemValue))) {
				$displayCols = quoteCols($db, array_keys($itemsDisplay));
				$displayVals = quoteVals($db, array_values($itemsDisplay));
				array_walk($displayCols, array('DB', 'quoteCol'));
				array_walk($displayVals, array($db, 'quote'));
				$sql = "\nINSERT INTO `survey_items_display` (" . implode(', ', $displayCols). ") VALUES (" . implode(', ', $displayVals). ") ON DUPLICATE KEY UPDATE session_id=VALUES(session_id);";
				fwrite($fp, $sql);
			}
		}

		$processed++;
		if ($processed % 100 == 0) {
			echo "\n sleeping for 2 seconds...";
			sleep(2);
		}
	} else {
		$sess = !empty($runSession->session) ? $runSession->session : null;
		$missing = "\n\n/* missing entry - unit session_id : {$entry['session_id']}; session: {$sess} */";
		echo $missing;
		fwrite($fp, $missing);
	}
}
fclose($fp);

quit("Rows processed: {$processed}\n SQL file: {$sqlBackupFile}", 0);
