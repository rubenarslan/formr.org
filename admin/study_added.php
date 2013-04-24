<?php
require_once '../includes/define_root.php';
unset($_SESSION['study_id']);
unset($_GET['study_id']);
require_once INCLUDE_ROOT . "config/config.php";

## Get Markdown class
require INCLUDE_ROOT. 'Markdown/Michelf/Markdown.php';
use \Michelf\Markdown AS Markdown;

$ok = false;
$errors = $messages = array();

if(!userIsAdmin()) 
{
	header("Location: ../index.php");
	exit;
}
elseif(empty($_POST))
{
	header("Location: ../acp/add_study.php");
	exit;
}
elseif(!isset($_POST['study_id']))
{
	$study=new Study;
	$study->Constructor($_POST['name'],$_POST['name'],$currentUser->id);
	if(!$study->status OR !$study->Register() OR !$study->CreateDB()) 
	{
		$errors[] = implode("</li><li>",$study->GetErrors());
	}
	else 
	{
		$messages[] = 'Study successfully constructed.';
		$_GET['study_id'] = $study->id;
	}
}
elseif(isset($_POST['study_id']))
{
	$messages[] = 'Study ID taken from POST. Existing study is being modified.';
	
	$id=0;
	if(isset($_POST['study_id'])) {
	  $id = $_POST['study_id'];
	  $_SESSION['study_id'] = $id;
	}
	
	$study=new Study;
	$study->fillIn($id);
	if(!defined("TABLEPREFIX")) define('TABLEPREFIX',$study->prefix."_");
	
	require_once INCLUDE_ROOT.'includes/settings.php';
	require_once INCLUDE_ROOT.'includes/variables.php';
}
require_once INCLUDE_ROOT.'view_header.php';
?>
<ul class="nav nav-tabs">
	<li><a href="<?=WEBROOT?>acp/acp.php">Zum Admin-Überblick</a></li>
</ul>

<?php


if (empty($errors) AND !isset($_FILES['uploaded'])) {
	$errors[] = 'No file';
}
elseif(empty($errors))
{
	umask(0002);
	ini_set('memory_limit', '256M');
	$target = "upload/";
	$target = $target . basename( $_FILES['uploaded']['name']) ;
	if (file_exists($target)) 
	{
	  rename($target,$target . "-overwritten-" . date('Y-m-d-H:m'));
	  $messages[] = "Eine Datei mit gleichem Namen existierte schon und wurde unter " . $target . "-overwritten-" . date('Y-m-d-H:m') . " gesichert.<br />";
	}
	if(!move_uploaded_file($_FILES['uploaded']['tmp_name'], $target)) 
	{
		$errors[] = "Sorry, es gab ein Problem bei dem Upload.<br />";
		var_dump($_FILES);
	} else {
		$messages[] = "Datei $target wurde hochgeladen<br />";
		$ok = true;
	}
}





// Leere / erstelle items
if ($ok):	
	// Include PHPExcel_IOFactory
	require_once INCLUDE_ROOT.'PHPExcel/Classes/PHPExcel/IOFactory.php';

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
	$messages[] = date('H:i:s') . " Iterate worksheets" . EOL;

  //  Get worksheet dimensions
	$worksheet = $objPHPExcel->getSheet(0); 
	
	$allowed_columns = array('id', 'variablenname', 'wortlaut', 'altwortlautbasedon', 'altwortlaut', 'typ', 'antwortformatanzahl', 'ratinguntererpol', 'ratingobererpol', 'MCalt1', 'MCalt2', 'MCalt3', 'MCalt4', 'MCalt5', 'MCalt6', 'MCalt7', 'MCalt8', 'MCalt9', 'MCalt10', 'MCalt11', 'MCalt12', 'MCalt13', 'MCalt14', 'optional', 'class' ,'skipif');
	$used_columns = array('id', 'variablenname', 'wortlaut', 'altwortlautbasedon', 'altwortlaut', 'typ', 'antwortformatanzahl', 'MCalt1', 'MCalt2', 'MCalt3', 'MCalt4', 'MCalt5', 'MCalt6', 'MCalt7', 'MCalt8', 'MCalt9', 'MCalt10', 'MCalt11', 'MCalt12', 'MCalt13', 'MCalt14', 'optional', 'class' ,'skipif');
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
					
					if(in_array($val,array('vpncode','created','ended'))):
						$errors[] = "Zeile $row_number: Itemname '$val' ist nicht erlaubt.";
					endif;

					if(($previous = array_search(strtolower($val),$variablennames)) === false):
						$variablennames[$row_number] = strtolower($val);	
					else:
						$errors[] = "Zeile $row_number: Itemname '$val' kam bereits vor, zuletzt in Zeile $previous.";
					endif;
				elseif($col == 'wortlaut' OR $col == 'altwortlaut'):
					$val = Markdown::defaultTransform($val); // transform upon insertion into db instead of at runtime
				elseif($col == 'optional'):
					$val = ($val===null OR $val===0) ? 0 : 1; // allow * etc.
				elseif($col == 'ratinguntererpol'):
					$col = 'MCalt1';
				elseif($col == 'ratingobererpol'):
					$col = 'MCalt2';
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
endif;

if(!empty($errors)) {
	echo "<h1 style='color:red'>Fehler:</h1>
	<ul><li>";
	echo implode("</li><li>",$errors);
	echo "</li></ul>";
}
else{
	if (table_exists(ITEMSTABLE)):
		mysql_query("drop table `".ITEMSTABLE."`;") or die (mysql_error());
		$messages[] = "Existierende Itemtabelle ".ITEMSTABLE." wurde gelöscht.<br />";	
	endif;

	createItemsTable();	

	mysql_close();

	// Connect to an ODBC database using driver invocation
	require_once INCLUDE_ROOT."Model/DB.php";
	
	$dbh = new DB();
	
	$dbh->beginTransaction();
	
	$stmt = $dbh->prepare('INSERT INTO `'.ITEMSTABLE.'` (id,
        variablenname,
        wortlaut,
        altwortlautbasedon,
        altwortlaut,
        typ,
        optional,
        antwortformatanzahl,
        MCalt1, MCalt2,	MCalt3,	MCalt4,	MCalt5,	MCalt6,	MCalt7,	MCalt8,	MCalt9,	MCalt10, MCalt11,	MCalt12,	MCalt13,	MCalt14,
        class,
        skipif) VALUES (:id,
		:variablenname,
		:wortlaut,
		:altwortlautbasedon,
		:altwortlaut,
		:typ,
		:optional,
		:antwortformatanzahl,
		:mcalt1, :mcalt2,	:mcalt3,	:mcalt4,	:mcalt5,	:mcalt6,	:mcalt7,	:mcalt8,	:mcalt9,	:mcalt10, :mcalt11,	:mcalt12,	:mcalt13,	:mcalt14,
		:class,
		:skipif
		)');

	foreach($data as $row) 
	{
	  foreach ($used_columns as $param) 
	  {
		  $stmt->bindParam(":$param", $row[$param]);
	  }
	 $stmt->execute() or die(print_r($stmt->errorInfo(), true));
	}
	
    if ($dbh->commit()) 
	{
		$dbh = null;
		$messages[] = "Datei wurde erfolgreich importiert.";
		echo "<h1><a href='../acp/view_study.php?id={$study->id}'>"._('Zur Studie').'</a></h1>';
		mysql_connect($DBhost,$DBuser,$DBpass) or die("Datenbank-Verbindung fehlgeschlagen. Bitte versuchen Sie es noch einmal.");
		mysql_select_db($DBname) or die("Datenbank-Auswahl fehlgeschlagen. Bitte versuchen Sie es noch einmal.");
		/* } */
		mysql_query("set names 'utf8';");
		deleteresults(); # only if conditions are met, otherwise ask for confirmation and show number of rows
    }
    else print_r($dbh->errorInfo());
}

if(isset($data) AND isset($messages)):
	echo "<h3>Meldungen:</h3>
	<ul><li>";
	echo implode("</li><li>",$messages);
	echo "</li></ul>";
	?>
	<pre style="overflow:scroll;height:100px;">
	<?php
	var_dump($data);
	?>
	</pre>
	<?php
else:
	echo "<h2>Nothing imported</h2>";
endif;
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require_once INCLUDE_ROOT.'view_footer.php';
