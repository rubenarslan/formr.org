<?php
require ('admin_header.php');
umask(0002);
if (!isset($_FILES['uploaded'])) {
	header("Location: uploaditems.php");
	break;
}

// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');
require ('includes/design.php');

echo "<table width=\"" . SRVYTBLWIDTH . "\">";
echo "<tr class=\"adminmessage\"><td>";

$target = "upload/";
$target = $target . basename( $_FILES['uploaded']['name']) ;

if (file_exists($target)) {
  rename($target,$target . "-overwritten-" . date('Y-m-d-H:m'));
  echo "Eine Datei mit gleichem Namen existierte schon und wurde unter " . $target . "-overwritten-" . date('Y-m-d-H:m') . " gesichert.<br />";
}
$ok=1;

$file_type=substr($_FILES['uploaded']['name'],strlen($_FILES['uploaded']['name'])-3,3);
/*
FIX: Maximale Größe und richtigen Dateityp kontrollieren!

$_FILES['userfile']['name']
// Der ursprüngliche Dateiname auf der Client Maschine. 

$_FILES['userfile']['type']
// Der Mime-Type der Datei, falls der Browser diese Information zur Verfügung gestellt hat. Ein Beispiel wäre "image/gif". 

$_FILES['userfile']['size']
// Die Größe der hochgeladenen Datei in Bytes. 

$_FILES['userfile']['tmp_name']
// Der temporäre Dateiname, unter dem die hochgeladene Datei auf dem Server gespeichert wurde. 

*/

if(!move_uploaded_file($_FILES['uploaded']['tmp_name'], $target)) {
  echo "Sorry, es gab ein Problem bei dem Upload.<br />";
		var_dump($_FILES);
  $ok = 0;
} else {
  echo "Datei $target wurde hochgeladen<br />";
}


// Leere / erstelle items
if (!table_exists(ITEMSTABLE, $database) && $ok!=0) {
// FIX Hier limitieren wir auf 14 MC-Alternativen! Anpassung ist notwendig, wenn mehr oder weniger gegeben werden.
		$query = "CREATE TABLE IF NOT EXISTS `".ITEMSTABLE."` (
  `id` int(11) NOT NULL,
  `variablenname` varchar(100) NOT NULL,
  `wortlaut` text NOT NULL,
  `altwortlautbasedon` varchar(150) NOT NULL,
  `altwortlaut` text NOT NULL,
  `typ` varchar(100) NOT NULL,
  `antwortformatanzahl` int(100) NOT NULL,
  `ratinguntererpol` text NOT NULL,
  `ratingobererpol` text NOT NULL,
  `MCalt1` text NOT NULL,
  `MCalt2` text NOT NULL,
  `MCalt3` text NOT NULL,
  `MCalt4` text NOT NULL,
  `MCalt5` text NOT NULL,
  `MCalt6` text NOT NULL,
  `MCalt7` text NOT NULL,
  `MCalt8` text NOT NULL,
  `MCalt9` text NOT NULL,
  `MCalt10` text NOT NULL,
  `MCalt11` text NOT NULL,
  `MCalt12` text NOT NULL,
  `MCalt13` text NOT NULL,
  `MCalt14` text NOT NULL,
  `Teil` varchar(255) NOT NULL,
  `relevant` char(1) NOT NULL,
  `skipif` text NOT NULL,
  `special` varchar(100) NOT NULL,
  `rand` varchar(10) NOT NULL,
  `study` varchar(100) NOT NULL,
  PRIMARY KEY  (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;";
	mysql_query($query);
	if(DEBUG) {
		echo $query;	
		echo mysql_error();
	}
} elseif ($ok!=0) {
	$query = "truncate ".ITEMSTABLE.";";
	mysql_query($query);
	echo "Existierende Itemtabelle wurde geleert.<br />";
	if(DEBUG) {
		echo $query;	
		echo mysql_error();
	}
} elseif ($ok==0) {
	echo "Es wurden keine Änderungen an der Datenbank vorgenommen.";
	// $ok muss 0 gewesen sein
}

// Du hast nun entweder ein $ok =1 oder 0 und auf jeden Fall eine existierende, leere Itemtabelle
 if($ok!=0 AND ($file_type=="ods" || $file_type=="xls" || $file_type=="csv")) {
  
  require_once "SpreadsheetReaderFactory.php";
  $spreadsheetsFilePath=$target; 
  $reader=SpreadsheetReaderFactory::reader($spreadsheetsFilePath);
	if(!$reader) {
		die(var_dump(is_readable($spreadsheetsFilePath)).$spreadsheetsFilePath. " something wrong with the path.");
	}
  $sheets= $reader->read($spreadsheetsFilePath);

  foreach($sheets as $sheet) {
    foreach($sheet as $sh) {
      $skipif=$sh[25];
      if( $skipif != "" ) {
        // is it valid?
        $val = json_decode($skipif, true);
        if( is_null($val) ) {
          echo $sh[0]." - ".$sh[1]." cannot be decoded: check the skipif!<br/>";
        }
      }
    } 
  }

  if($ok==1) {
    $sql=array();
    $cnt=0;
    foreach($sheets as $sheet) {
      foreach($sheet as $row) {
        if($cnt!=0) 
          $sql[] = "(\"". implode("\",\"",array(mysql_real_escape_string($row[0]), mysql_real_escape_string($row[1]), mysql_real_escape_string($row[2]), mysql_real_escape_string($row[3]), mysql_real_escape_string($row[4]), mysql_real_escape_string($row[5]), mysql_real_escape_string($row[6]), mysql_real_escape_string($row[7]), mysql_real_escape_string($row[8]), mysql_real_escape_string($row[9]), mysql_real_escape_string($row[10]), mysql_real_escape_string($row[11]), mysql_real_escape_string($row[12]), mysql_real_escape_string($row[13]), mysql_real_escape_string($row[14]), mysql_real_escape_string($row[15]), mysql_real_escape_string($row[16]), mysql_real_escape_string($row[17]), mysql_real_escape_string($row[18]), mysql_real_escape_string($row[19]), mysql_real_escape_string($row[20]), mysql_real_escape_string($row[21]), mysql_real_escape_string($row[22]), mysql_real_escape_string($row[23]), mysql_real_escape_string($row[24]), mysql_real_escape_string($row[25]), mysql_real_escape_string($row[26]), mysql_real_escape_string($row[27]), mysql_real_escape_string($row[28]) )) ."\")";
        else 
          $cnt=1;
      }    
    }
    $query = 'INSERT INTO `'.ITEMSTABLE.'` (id,
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
        study) VALUES '.implode(',', $sql);

    // load data local infile needs privileges we don't have on www2
    //	$query = "LOAD DATA LOCAL INFILE '$import' REPLACE INTO TABLE `".ITEMSTABLE."` CHARACTER SET utf8 FIELDS TERMINATED BY ';' OPTIONALLY ENCLOSED BY '\"' IGNORE 1 LINES;";
    //	mysql_query($query);
    if(DEBUG) {
      echo $query;
      echo mysql_error();
    }
    if (mysql_query($query)) {
      echo "Datei wurde erfolgreich importiert.";
		deleteresults(); # only if conditions are met, otherwise ask for confirmation and show number of rows
    }
    echo mysql_error();
  }
  
}

echo "</td></tr><tr class=\"odd\"><td><form action=\"index.php\"><input type=\"submit\" value=\"Weiter\"></form></td></tr></table>";

// schließe main-div
echo "</div>\n";
// binde Navigation ein
require ('includes/navigation.php');
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');

?>