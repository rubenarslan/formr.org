<?php
## Get Markdown class
require_once INCLUDE_ROOT. 'Markdown/Michelf/Markdown.php';
use \Michelf\Markdown AS Markdown;

class SpreadsheetReader
{
	public $messages = array();
	public $errors = array();
	

	public function backupTSV($array,$filename)
	{
		$objPHPExcel = $this->objectFromArray($array);
		
		$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'CSV');
		$objWriter->setDelimiter("\t");
		$objWriter->setEnclosure("");
		
	    $objWriter->save($filename);
	}
	protected function objectFromArray($array)
	{
		// Include PHPExcel_IOFactory
		require_once INCLUDE_ROOT.'PHPExcel/Classes/PHPExcel/IOFactory.php';

	    $objPHPExcel = new PHPExcel();
		array_unshift($array, array_keys(current($array)));
		$objPHPExcel->getSheet(0)->fromArray($array);
		
		return $objPHPExcel;
	}
	public function exportCSV($array,$filename)
	{
		$objPHPExcel = $this->objectFromArray($array);
		
		$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'CSV');
		
	    header('Content-Disposition: attachment;filename="'.$filename.'.csv"');
	    header('Cache-Control: max-age=0');
		header('Content-type: text/csv');

	    $objWriter->save('php://output');
	    exit;
	}
	public function saveTSV($array,$filename)
	{
		$objPHPExcel = $this->objectFromArray($array);
		
		$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'CSV');
		$objWriter->setDelimiter("\t");
		$objWriter->setEnclosure("");
		
	    $objWriter->save($filename);
	}
	public function exportTSV($array,$filename)
	{
		$objPHPExcel = $this->objectFromArray($array);
		
		$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'CSV');
		$objWriter->setDelimiter("\t");
		$objWriter->setEnclosure("");
		
	    header('Content-Disposition: attachment;filename="'.$filename.'.tab"');
	    header('Cache-Control: max-age=0');
		header('Content-type: text/csv'); // or maybe text/tab-separated-values?

	    $objWriter->save('php://output');
	    exit;
	}
	public function exportCSV_german($array,$filename)
	{
		$objPHPExcel = $this->objectFromArray($array);
		
		$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'CSV');
		$objWriter->setDelimiter(";");
		$objWriter->setEnclosure('"');
		
	    header('Content-Disposition: attachment;filename="'.$filename.'.csv"');
	    header('Cache-Control: max-age=0');
		header('Content-type: text/csv');

	    $objWriter->save('php://output');
	    exit;
	}
	public function exportXLS($array,$filename)
	{
		$objPHPExcel = $this->objectFromArray($array);
		
		$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel5');
		
	    header('Content-Disposition: attachment;filename="'.$filename.'.xls"');
	    header('Cache-Control: max-age=0');
	    header('Content-Type: application/vnd.ms-excel'); 

	    $objWriter->save('php://output');
	    exit;
	}
	public function exportXLSX($array,$filename)
	{
		$objPHPExcel = $this->objectFromArray($array);
		
		$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel2007');
		
	    header('Content-Disposition: attachment;filename="'.$filename.'.xls"');
	    header('Cache-Control: max-age=0');
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

	    $objWriter->save('php://output');
	    exit;
	}
	private function translate_legacy_column($col)
	{
		if(strtolower($col)=='variablenname')
			$col = 'name';
		elseif(strtolower($col)=='typ')
			$col = 'type'
		elseif(strtolower($col)=='wortlaut' or strtolower($col)=='text')
			$col = 'text'
		elseif(strtolower(substr($col,0,5))=='mcalt')
			$col = 'choice'.substr($field,5);
		elseif(strtolower($col)=='ratinguntererpol')
			$col = 'choice1';
		elseif(strtolower($col)=='ratingobererpol')
			$col = 'choice2';
		
		return strtolower($col);
	}
	public function readItemTableFile($inputFileName)
	{
		$errors = $messages = array();
		
		// Include PHPExcel_IOFactory
		require_once INCLUDE_ROOT.'PHPExcel/Classes/PHPExcel/IOFactory.php';

		define('EOL',(PHP_SAPI == 'cli') ? PHP_EOL : '<br />');

		if (!file_exists($inputFileName)):
			exit($inputFileName. " does not exist." . EOL);
		endif;

		$callStartTime = microtime(true);

		//  Identify the type of $inputFileName 
		$inputFileType = PHPExcel_IOFactory::identify($inputFileName);
		//  Create a new Reader of the type that has been identified 
		$objReader = PHPExcel_IOFactory::createReader($inputFileType);
		//  Load $inputFileName to a PHPExcel Object 

		///  Advise the Reader that we only want to load cell data 
		$objReader->setReadDataOnly(true);


		try {
		  // Load $inputFileName to a PHPExcel Object
		  $objPHPExcel = PHPExcel_IOFactory::load($inputFileName);
		} catch(PHPExcel_Reader_Exception $e) {
		  die('Error loading file: '.$e->getMessage());
		}
		$messages[] = date('H:i:s') . " Iterate worksheets" . EOL;

	  //  Get worksheet dimensions
		$worksheet = $objPHPExcel->getSheet(0); // fixme: get sheet named items in the future
	
		$allowed_columns = array('id', 'variablenname', 'wortlaut', 'altwortlautbasedon', 'altwortlaut', 'typ', 'antwortformatanzahl', 'ratinguntererpol', 'ratingobererpol', 'choice1', 'choice2', 'choice3', 'choice4', 'choice5', 'choice6', 'choice7', 'choice8', 'choice9', 'choice10', 'choice11', 'choice12', 'choice13', 'choice14', 'optional', 'class' ,'skipif');
		$used_columns = array('id', 'variablenname', 'wortlaut', 'altwortlautbasedon', 'altwortlaut', 'typ', 'antwortformatanzahl', 'choice1', 'choice2', 'choice3', 'choice4', 'choice5', 'choice6', 'choice7', 'choice8', 'choice9', 'choice10', 'choice11', 'choice12', 'choice13', 'choice14', 'optional', 'class' ,'skipif');
		$allowed_columns = array_map('strtolower', $allowed_columns);
		$used_columns = array_map('strtolower', $used_columns);

		// non-allowed columns will be ignored, allows to specify auxiliary information if needed
	
		$columns = array();
		$nr_of_columns = PHPExcel_Cell::columnIndexFromString($worksheet->getHighestColumn());
		for($i = 0; $i< $nr_of_columns;$i++):
			$col_name = strtolower($worksheet->getCellByColumnAndRow($i, 1)->getCalculatedValue() );
			if(in_array($col_name,$allowed_columns) ):
				$columns[$i] = $col_name;
			endif;
		endfor;
	  	$messages[] = 'Worksheet - ' . $worksheet->getTitle();

	#	var_dump($columns);

		$variablennames = $data = array();
	
	  	foreach($worksheet->getRowIterator() AS $row):
			$row_number = $row->getRowIndex();

			if($row_number == 1): # skip table head
				continue;
			endif;
	  		$cellIterator = $row->getCellIterator();
	  		$cellIterator->setIterateOnlyExistingCells(false); // Loop all cells, even if it is not set
		
			$data[$row_number] = array();
		
	 		foreach($cellIterator AS $cell):
	  			if (!is_null($cell) ):
					$column_number = $cell->columnIndexFromString( $cell->getColumn() ) - 1;

					if(!array_key_exists($column_number,$columns)) continue; // skip columns that aren't allowed
				
					$col = $columns[$column_number];
					$val = $cell->getCalculatedValue();
					
					$col = $this->translate_legacy_column($col);
					
					if($col == 'id'):
						$val = $row_number;
				
					elseif($col == 'variablenname'):
						if(trim($val)==''):
							$messages[] = "Row $row_number: variable name empty. Row skipped.";
							if(isset($data[$row_number])):
								unset($data[$row_number]);
							endif;
							continue 2; # skip this row
								
						elseif(!preg_match("/[a-zA-Z][a-zA-Z0-9_]{2,20}/",$val)):
							$errors[] = __("The variable name '%s' is invalid. It has to be between 3 and 20 characters. It needs to start with a letter and may not contain anything other than a-Z_0-9.",$val);

						endif;
					
						if(in_array($val,array('session_id','created','modified','ended'))):
							$errors[] = "Row $row_number: variable name '$val' is not permitted.";
						endif;

						if(($previous = array_search(strtolower($val),$variablennames)) === false):
							$variablennames[$row_number] = strtolower($val);	
						else:
							$errors[] = "Row $row_number: variable name '$val' already appeared, last in row $previous.";
						endif;
					elseif($col == 'label'):
						$val = Markdown::defaultTransform($val); // transform upon insertion into db instead of at runtime
					elseif($col == 'optional'):
						$val = ($val===null OR $val===0) ? 0 : 1; // allow * etc.
					elseif( is_int( $pos = strpos("choice",$col) ) ):
					  $nr = substr($col, 5);
					  
				  endif;

				endif; // validation
				
			  
				$data[$row_number][ $col ] = $val;
				
				endif; // cell null
			
			endforeach; // cell loop
		
			// row has been put into array
			if(!isset($data[$row_number]['id'])) $data[$row_number]['id'] = $row_number;

			require_once INCLUDE_ROOT."Model/Item.php";
			$item = legacy_translate_item($data[$row_number]);
	#		$item = new $class($data[$row_number]['typ'],$data[$row_number]['variablenname'],$data[$row_number]);
			$val_errors = $item->validate();
		
			if(!empty($val_errors)):
				$errors = $errors + $val_errors;
				unset($data[$row_number]);
			endif;
		
		endforeach; // row loop


		$callEndTime = microtime(true);
		$callTime = $callEndTime - $callStartTime;
		$messages[] = 'Call time to read Workbook was ' . sprintf('%.4f',$callTime) . " seconds" . EOL .  "$row_number rows were read.";
		// Echo memory usage
		$messages[] = date('H:i:s') . ' Current memory usage: ' . (memory_get_usage(true) / 1024 / 1024) . " MB" ;
		
		$this->messages = $messages;
		$this->errors = $errors;
		return $data;
	}
}