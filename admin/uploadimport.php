<?php
require ('admin_header.php');
umask(0002);
if (!isset($_FILES['uploaded'])) {
	header("Location: uploaditems.php");
	break;
}
ini_set('memory_limit', '256M');

// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');
$target = "upload/";
$target = $target . basename( $_FILES['uploaded']['name']) ;

if (file_exists($target)) {
  rename($target,$target . "-overwritten-" . date('Y-m-d-H:m'));
  echo "Eine Datei mit gleichem Namen existierte schon und wurde unter " . $target . "-overwritten-" . date('Y-m-d-H:m') . " gesichert.<br />";
}
$ok=1;

$file_type=substr($_FILES['uploaded']['name'],strlen($_FILES['uploaded']['name'])-3,3);


if(!move_uploaded_file($_FILES['uploaded']['tmp_name'], $target)) {
  echo "Sorry, es gab ein Problem bei dem Upload.<br />";
		var_dump($_FILES);
  $ok = 0;
} else {
  echo "Datei $target wurde hochgeladen<br />";
}


// Leere / erstelle items
if (!table_exists(ITEMSTABLE) AND $ok) {
// FIX Hier limitieren wir auf 14 MC-Alternativen! Anpassung ist notwendig, wenn mehr oder weniger gegeben werden.
	createItemsTable();
} elseif ($ok) {
	$query = "truncate ".ITEMSTABLE.";";
	mysql_query($query);
	echo "Existierende Itemtabelle wurde geleert.<br />";
	if(DEBUG) {
		echo $query;	
		echo mysql_error();
	}
} elseif (!$ok) {
	echo "Es wurden keine Änderungen an der Datenbank vorgenommen.";
	// $ok muss 0 gewesen sein
}
mysql_close();

// Du hast nun entweder ein $ok =1 oder 0 und auf jeden Fall eine existierende, leere Itemtabelle
if($ok):

	// Include PHPExcel_IOFactory
	require_once '../PHPExcel/Classes/PHPExcel/IOFactory.php';

	$inputFileName = $target;

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
	echo date('H:i:s') , " Iterate worksheets" , EOL;

  //  Get worksheet dimensions
	$worksheet = $objPHPExcel->getSheet(0); 
	
	$allowed_columns = array('id', 'variablenname', 'wortlaut', 'altwortlautbasedon', 'altwortlaut', 'typ', 'antwortformatanzahl', 'ratinguntererpol', 'ratingobererpol', 'MCalt1', 'MCalt2', 'MCalt3', 'MCalt4', 'MCalt5', 'MCalt6', 'MCalt7', 'MCalt8', 'MCalt9', 'MCalt10', 'MCalt11', 'MCalt12', 'MCalt13', 'MCalt14', 'Teil', 'relevant', 'skipif', 'special', 'rand', 'study');
	$allowed_columns = array_map('strtolower', $allowed_columns);

	// non-allowed columns will be ignored, allows to specify auxiliary information if needed
	
	$columns = array();
	$nr_of_columns = PHPExcel_Cell::columnIndexFromString($worksheet->getHighestColumn());
	for($i = 0; $i< $nr_of_columns;$i++):
		$col_name = strtolower($worksheet->getCellByColumnAndRow($i, 1)->getCalculatedValue() );
		if(in_array($col_name,$allowed_columns) ):
			$columns[$i] = $col_name;
		endif;
	endfor;
  	echo 'Worksheet - ' , $worksheet->getTitle() , EOL;

#	var_dump($columns);

	$data = $errors = $messages = array();
	
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
				if($col == 'id'):
					$val = $row_number;
					
				elseif($col == 'variablenname'):
					if(trim($val)==''):
						$messages[] = "Zeile $row_number: Variablenname leer. Zeile übersprungen.";
						if(isset($data[$row_number])):
							unset($data[$row_number]);
						endif;
						continue 2; # skip this row
					endif;
					
				elseif( is_int( $pos = strpos("mcalt",$col) ) ):
				  $nr = substr($col, $pos + 5);
				  if(trim($val) != '' AND $nr > $data[$row_number]['antwortformatanzahl'] ): 
					  $errors[] = "Zeile $row_number: mehr Antwortoptionen als angegeben!";
				  endif;
				  				
				endif; // validation
				
			  
			$data[$row_number][ $col ] = $val;
				
			endif; // cell null
			
		endforeach; // cell loop
		
		
		// row has been put into array
		if(!isset($data[$row_number]['id'])) $data[$row_number]['id'] = $row_number;

		require_once "../includes/Item.php";
		$class = "Item_".strtolower($data[$row_number]['typ']);
		if(!class_exists($class)) 
			$class = 'Item';
		$item = new $class($data[$row_number]['typ'],$data[$row_number]['variablenname'],$data[$row_number]);
		$val_errors = $item->validate();
		
		if(!empty($val_errors)):
			$errors = $errors + $val_errors;
			unset($data[$row_number]);
		endif;
		
	endforeach; // row loop


  $callEndTime = microtime(true);
  $callTime = $callEndTime - $callStartTime;
  $messages[] = 'Call time to read Workbook was ' . sprintf('%.4f',$callTime) . " seconds" . EOL .  "$row_number rows were read." . EOL;
  // Echo memory usage
  $messages[] = date('H:i:s') . ' Current memory usage: ' . (memory_get_usage(true) / 1024 / 1024) . " MB" . EOL;
endif;

?>
<pre style="overflow:scroll;height:100px;">
<?php
var_dump($data);
echo '</pre>';

if(empty($errors)) {

	// Connect to an ODBC database using driver invocation
	require_once "../includes/DB.php";
	
	$dbh = new DB();
	
	$dbh->beginTransaction();
	
	$stmt = $dbh->prepare('INSERT INTO `'.ITEMSTABLE.'` (id,
        variablenname,
        wortlaut,
        altwortlautbasedon,
        altwortlaut,
        typ,
        antwortformatanzahl,
        ratinguntererpol,
        ratingobererpol,
        MCalt1, MCalt2,	MCalt3,	MCalt4,	MCalt5,	MCalt6,	MCalt7,	MCalt8,	MCalt9,	MCalt10, MCalt11,	MCalt12,	MCalt13,	MCalt14,
        Teil,
        relevant,
        skipif,
        special,
        rand,
        study) VALUES (:id,
		:variablenname,
		:wortlaut,
		:altwortlautbasedon,
		:altwortlaut,
		:typ,
		:antwortformatanzahl,
		:ratinguntererpol,
		:ratingobererpol,
		:mcalt1, :mcalt2,	:mcalt3,	:mcalt4,	:mcalt5,	:mcalt6,	:mcalt7,	:mcalt8,	:mcalt9,	:mcalt10, :mcalt11,	:mcalt12,	:mcalt13,	:mcalt14,
		:teil,
		:relevant,
		:skipif,
		:special,
		:rand,
		:study)');

	  foreach($data as $row) {
		  foreach ($allowed_columns as $param) {
#			  if(!isset($row[$param]))
#				  $row[$param] = null;
			  
			  $stmt->bindParam(":$param", $row[$param]);
			  
		  }
	   	 $stmt->execute() or die(print_r($stmt->errorInfo(), true));
	  }
    if ($dbh->commit()) {
		echo "Datei wurde erfolgreich importiert.";
		mysql_connect($DBhost,$DBuser,$DBpass) or die("Datenbank-Verbindung fehlgeschlagen. Bitte versuchen Sie es noch einmal.");
		mysql_select_db($DBname) or die("Datenbank-Auswahl fehlgeschlagen. Bitte versuchen Sie es noch einmal.");
		/* } */
		mysql_query("set names 'utf8';");
		deleteresults(); # only if conditions are met, otherwise ask for confirmation and show number of rows
    }
    else print_r($dbh->errorInfo());
}
else {
	echo "<h1 style='color:red'>Fehler:</h1>
	<ul><li>";
	echo implode("</li><li>",$errors);
	echo "</li></ul>";
} 
echo "<h3>Meldungen:</h3>
<ul><li>";
echo implode("</li><li>",$messages);
echo "</li></ul>";

?>
<a href="index.php">Zum Admin-Überblick</a>
<?php
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');
