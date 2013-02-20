<?php
// und dann bitte noch die User-Funktionen zur Verfügung stellen!
// require('../includes/functions.php');

function setupadmin() {
    // set up the admin table
	$query  = "CREATE TABLE " . ADMINTABLE . " (`id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, `key` VARCHAR(100), `value` VARCHAR(150))";
    mysql_query($query) or die(mysql_error());

	$defaults = array(
		array( "logo" => "hu.gif"),
		array( "welcome" => "Welcome Message"),
		array( "title" => "Title"),
		array( "description" => "Description"),
		array( "author" => "Author"),
		array( "admin_email" => "email@host.com"),
		array( "copyright" => "Copyright"),
		array( "pagetopic" => "Page Topic"),
		array( "srvytblwidth" => "800"),
		array( "maxnumitems" => "15"),
		array( "txtbxwidth" => "50"),
		array( "imagefolder" => "images/"),
		array( "fileuploadmaxsize" => "100000"),
		array( "loginpage" => "survey.php"),
		array( "userpool" => "open"),
		array( "diary" => "true"),
		array( "timed" => "true"),
		array( "partner" => "true"),
		array( "random" => "true"),
		array( "allowedtypes" => 'rating,offen,pse,mc,instruktion,mmc,fork,ym,snpartner,datepicker,email,imc'),
		array( "specialtestsallowed" => "SN"),
		array( "specialteststrigger" => "foo"),
		array( "snmaxentries" => "35"),
		array( "timezone" => "Europe/Berlin"),
		array( "debug" => "true"),
		array( "skipif_debug" => "true"),
		array( "suppress_fork" => "false"),
		array( "maxsendemails" => "25"),
		array( "email_header_from" => "user@host.com"),
		array( "email_header_reply_to" => "user@host.com"),
		array( "email_header_cc" => ""),
		array( "email_header_bcc" => ""),
		array( "email_subject_text" => ""),
		array( "email_body_text" => ""),
		array( "primary_color" => "#ff0000"),
		array( "secondary_color" => "#00ff00"),
		array( "odd_color" => "#ccc"),
		array( "even_color" => "#eee")
	);

	foreach($defaults as $item) {
		foreach($item as $key => $value) {
			$insert = "INSERT INTO ".ADMINTABLE." SET `key`='".$key."',`value`='".$value."' ";
			mysql_query($insert) or die(mysql_error());
		}
	}
}

//Admin-Funtionen
function updateitems(){
    // FIX: Besser wäre auch hier eine zentrale Einstellung in Settings (array), und ein Loop durch die gegebenen Variablen.
    if (isset($_POST["id"])) {
        // Nur, wenn auch wirklich etwas (kontrolliert wird nur id) gepostet wurde, dann auch Datenbank updaten.
        $id = $_POST["id"];
        $variablenname = $_POST["variablenname"];
        $wortlaut = $_POST["wortlaut"]; 
        $altwortlautbasedon = $_POST["altwortlautbasedon"];
        $altwortlaut = $_POST["altwortlaut"];
        $typ = $_POST["typ"];
        $antwortformatanzahl = $_POST["antwortformatanzahl"];
        $ratinguntererpol = $_POST["ratinguntererpol"];
        $ratingobererpol = $_POST["ratingobererpol"];
        // Anzahl von MC-Alternativen ist unbekannt, stelle sie daher fest, und loope mit dem POST-Befehl
        for ($k=1; $k <= howmanymcs(ITEMSTABLE, $DBname); $k++) {
            $variable = "MCalt".$k;
            if (isset($_POST["$variable"])) {
                $$variable = $_POST["$variable"];
            }
        }
        // Beginne mit dem Updaten der item-Tabelle (in dieser einen Zeile, s.u.)
        $query = "UPDATE " . ITEMSTABLE . " SET ";
        // FIX Auch das hier KANN man loopen lassen
        $query = $query . "wortlaut = '$wortlaut', ";
        $query = $query . "typ = '$typ', ";
        $query = $query . "altwortlaut='$altwortlaut', ";
        // Vergebe/Überschreibe Pole nur bei Rating (werden somit behalten, wenn Fehler gemacht wurde)
        if ($typ == "rating") {
            $query = $query . "ratingobererpol = '$ratingobererpol', ";
            $query = $query . "ratinguntererpol = '$ratinguntererpol', ";
        }
        // Vergebe/Überschreibe MC-Alternativen nur bei MC (werden somit behalten, wenn Fehler gemacht wurde)
        if ($typ == "mc") {
            for ( $counter = 1; $counter <= $antwortformatanzahl; $counter++) {
                $variable = "MCalt".$counter;
                $value = $$variable;
                // Prüfe, ob des die Variable in der Tabelle gibt
                if(!variable_exists(ITEMSTABLE, $DBname, $variable)) {
                    // Wenn Variable nicht besteht, erstelle sie
                    $subquery = "ALTER TABLE  `" . ITEMSTABLE . "` ADD  `" . $variable . "` VARCHAR(100) NOT NULL \n";
                    // Das soll natürlich sofort geschehen, und nicht erst nachher
                    mysql_query($subquery) or die(mysql_error());
                    // und jetzt kannst du sie auch schön in Query mit aufnehmen.
                }
                // nur neue Werte vergeben, wenn die auch schon übertragen wurden. Verhindert Überschreiben früher da gewesener Werte mit Empty
                if ($value != "") {
                    $query = $query . "$variable = '" . $value . "', ";
                }

            }
        }
        // Jetzt noch der Rest der Variablen, die alle Items haben
        $query = $query . "antwortformatanzahl = '$antwortformatanzahl', ";
        $query = $query . "variablenname = '$variablenname' ";
        // und das Ganze natürlich nur für dieses Item / diese Zeile
        $query = $query . "WHERE id = '$id'";
        // FIX mit in SQL aufnehmen: altwortlautbasedon = '$altwortlautbasedon', altwortlaut = '$altwortlaut',
        // funktionieren im Moment nicht, weil der Variablenname geändert werden muss, beinhaltet im Moment störendes minus
        mysql_query($query) or die(mysql_error());
        echo $query . mysql_error();
        resetitemdisplaytable();
    }
}
function get_results() {
	require("../includes/settings.php");
	$table = RESULTSTABLE;

	$query = "SELECT * FROM ".RESULTSTABLE;
	$results = mysql_query( $query ) or die( error_log( mysql_error() . " in export_results: \n" . $query));


	$csv = "";
	$fields = mysql_list_fields($DBname,RESULTSTABLE);
	$columns = mysql_num_fields($fields);

	for ($i = 0; $i < $columns; $i++) {
		$l=mysql_field_name($fields, $i);
		$csv .= '"'.$l.'"\t';
	}
	$csv .="\n";

	for($i=0; $i < mysql_num_rows($results); $i++) {
		$row = mysql_fetch_row($results);
		foreach( $row as $column_value) {
			$column_value = preg_replace("/[\r\n|\r|\n]/","\\n",$column_value);
			$rowwidth = count($row);
			$column_value = str_replace("\t","    ",$column_value);
			if($i!=($rowwidth)-1) $csv .=$column_value.'\t';
			else $csv .=$column_value;
		}
		$csv .= "\n";
	}
	return $csv;
}

function export_results() {
	$table = RESULTSTABLE;

	header("Content-type: application/csv");
	header("Content-Disposition: attachment; filename=$table".date('YmdHis').".csv");
	header("Pragma: no-cache");
	header("Expires: 0");

	echo get_results();
	exit;
}

function backup_results() {
        $filename = "../results_backups/".$table.date('YmdHis').".csv"; ## TODO: basepath sollte irgendwo zentral gesetzt sein oder so
	// Sichergehen, dass die Datei existiert und beschreibbar ist
	if (is_writable($filename)) {

	    // Wir öffnen $filename im "Anhänge" - Modus.
	    // Der Dateizeiger befindet sich am Ende der Datei, und
	    // dort wird $somecontent später mit fwrite() geschrieben.
	    if (!$handle = fopen($filename, "w")) {
	         die("Kann die Datei $filename nicht öffnen");
	    }

	    // Schreibe $somecontent in die geöffnete Datei.
	    if (!fwrite($handle, get_results())) {
				die("Kann in die Datei $filename nicht schreiben");
	    }
	else {
		return true;
	}
	    fclose($handle);

	} else {
	    die("Die Datei $filename ist nicht schreibbar");
	}
	
}

function printitemsforedit($allowedtypes, $id, $variablenname, $wortlaut, $altwortlautbasedon, $altwortlaut, $typ, $antwortformatanzahl, $ratinguntererpol, $ratingobererpol) {
    // FIX: Frei skalierbare MC-Alternativen: Wenn mehr als 5 muss entsprechende Variable in items erstellt werden.
    // Funktion erstellt eine Zeile von <tr> bis </tr> mit Editierfunktion
    // gerade oder ungerade Zeile?
    if( is_odd($id) ) {
        $oe = "even";
    } else {
        $oe = "odd";
    }
    // Mache eine Zeile auf, gib ihr Klasse odd/even
    echo "\n<tr class=\"" . $oe ."\">\n";
    // FIX: Method = POST
    echo "<form method=\"POST\" value=\"edititems.php\">\n";
    // id in Tabelle items als Zeilennummer
    echo "<td class=\"$typ\" id=\"$typ\"><a name=\"$id\">" . $id . "</a></td>\n";
    // Textfeld zur Änderung des Variablennamens
    echo "<td class=\"$typ\" id=\"$typ\">Variable:<br /><input type=\"text\" size=\"8\" name=\"variablenname\" value=\"$variablenname\"></td>\n";
    // Änderung des Wortlauts
    echo "<td class=\"$typ\" id=\"$typ\"><textarea cols=\"" .  22 . "\" rows=\"10\" name=\"wortlaut\" wrap=\"virtual\">" . $wortlaut . "</textarea></td>\n";
    // FIX: Alternativen Wortlaut und Bedingung dafür!
    echo "<td class=\"$typ\" id=\"$typ\"><textarea cols=\"" .  22 . "\" rows=\"10\" name=\"altwortlaut\" wrap=\"virtual\">" . $altwortlaut . "</textarea></td>\n";

    // echo "<td class=\"$typ\" id=\"$typ\">" . $altwortlautbasedon . "</td>";
    // echo "<td class=\"$typ\" id=\"$typ\">" . $altwortlaut . "</td>";
    echo "<td class=\"$typ\" id=\"$typ\">Typ:" . typesdropdown($allowedtypes, $typ) . "</td>\n";
    if ($typ != "instruktion") {
        // Nur, wenn NICHT Instruktion, dann
        echo "<td class=\"$typ\" id=\"$typ\">Umfang: <input type=\"text\" size=\"3\" name=\"antwortformatanzahl\" value=\"$antwortformatanzahl\"></td>\n";
        if ($typ == "rating") {
            // Wenn Rating, dann entsprechende Pole zur Verfügung stellen
            echo "<td class=\"$typ\" id=\"$typ\">unterer Pol:<br /><input type=\"text\" size=\"10\" name=\"ratinguntererpol\" value=\"$ratinguntererpol\"></td>\n";
            echo "<td class=\"$typ\" id=\"$typ\">oberer Pol:<br /><input type=\"text\" size=\"10\" name=\"ratingobererpol\" value=\"$ratingobererpol\"></td>\n";
        } else {
            if ($typ == "mc") {
                // Wenn Multiple Choice, dann Alternativen darstellen
                echo "<td class=\"$typ\" id=\"$typ\">Alternativen:</td><td class=\"$typ\" id=\"$typ\">\n";
                mcalternatives($id);
                echo "</td>\n";
            } else {
                for ( $counter = 1; $counter <= 2; $counter++) {
                    // Auffüllen der leer gebliebenen Zellen bei Rating (?)
                    echo "<td class=\"$typ\" id=\"$typ\"></td>\n";
                }
            }
        }
    } else {
        // Auffüllen der leer gebliebenen Zellen bei Instruktion
        for ( $counter = 1; $counter <= 3; $counter++) {
            echo "<td class=\"$typ\" id=\"$typ\"></td>\n";
        }
    }
    echo "<input type=\"hidden\" name=\"id\" value=\"$id\"><td class=\"$typ\" id=\"$typ\">Dieses Zeile<br /><input type=\"submit\" value=\"speichern\">\n</form>\n</tr>\n";
}

function typesdropdown($allowedtypes, $typ) {
    $output = "<select name=\"typ\">\n";
    foreach ($allowedtypes as $allowedtype) {
        if ($allowedtype == $typ) {
            $output = $output . "<option selected value=\"$allowedtype\">$allowedtype</option>\n";
        } else {
            $output = $output . "<option value=\"$allowedtype\">$allowedtype</option>\n";			
        }
    }
    $output = $output . "</select>\n";
    return $output;
}

function mcalternatives($id) {
    $query="SELECT * FROM " . ITEMSTABLE . " WHERE id ='$id'";
    $thismc=mysql_fetch_array(mysql_query($query));
    // Debug: Wie viele sind es? id ist unique, also darf es nur 1 sein!
    $nummc=mysql_numrows(mysql_query($query));
    if ($nummc > 1) die ("massiver Fehler! Die id ist 2x vergeben");
    for ($k=1; $k <= $thismc[antwortformatanzahl]; $k++) {
        echo "<input type=\"text\" name=\"MCalt" .$k . "\" value=\"" . $thismc[MCalt.$k] . "\"><br />\n";
    }
}


function deleteresults() {
    if (isset($_GET['confirm'])) $confirm = $_GET['confirm'];
	$how_much_already = mysql_query("SELECT * FROM ".RESULTSTABLE);
	$how_much_already = mysql_num_rows($how_much_already);

    if ($how_much_already<10 || $confirm=="576879ccc") {
		
		if($how_much_already<2 || backup_results()) {
	        $query= "DROP TABLE ".RESULTSTABLE;
	        mysql_query($query);
	        $message="Ergebnistabelle wurde gelöscht.<br />" . mysql_error();
			createresulttab();
		}
    } else {
        $message="Willst du wirklich die gesamte Ergebnistabelle mit bereits <big>$how_much_already</big> löschen? <a class=\"adminmessage\" href=\"index.php?action=deleteresults&confirm=576879ccc\">LÖSCHEN</a> / <a class=\"adminmessage\" href=\"index.php\">Bloß nicht!</a><br />";
    }
    echo $message;
    resetitemdisplaytable();
}

function createresulttab() {
    //Prüfe, ob Tabelle existiert
    if (table_exists(RESULTSTABLE, $DBname)) {
        // Wenn ja, dann breche ab.
        $message="Eine Ergebnistabelle existiert bereits.<br />Eine neue kann nur erstellt werden, wenn vorher die alte gelöscht wird.<br />Vorsicht! Dabei gehen alle bisher erhobenen Daten verloren.";
        echo $message;
    } else {
        // Prüfe, ob es überhaupt schon eine item-Tabelle gibt
        if (table_exists(ITEMSTABLE, $DBname)) {
            if(PARTNER AND DIARYMODE) {				
                $query="CREATE TABLE IF NOT EXISTS " . $DBname . ".".RESULTSTABLE." (
                    id INT NOT NULL AUTO_INCREMENT,
                    begansurveysmsintvar DATETIME DEFAULT NULL,
                    vpncode VARCHAR( 100 ) NOT NULL,
                    partnercode VARCHAR( 100 ) DEFAULT NULL,
                    study VARCHAR( 100 ) DEFAULT NULL,
                    iteration INT DEFAULT 1,
                    timestarted INT DEFAULT 0,
                    timefinished INT DEFAULT 0,
                    updated_at DATETIME DEFAULT NULL,
                    created_at DATETIME DEFAULT NULL,
                    UNIQUE (
                        id
                    )) ENGINE = MYISAM CHARACTER SET utf8 COLLATE utf8_general_ci;";
            } elseif(PARTNER AND !DIARYMODE) {
                $query="CREATE TABLE IF NOT EXISTS " . $DBname . ".".RESULTSTABLE." (
                    id INT NOT NULL AUTO_INCREMENT,
                    begansurveysmsintvar DATETIME DEFAULT NULL ,
                    vpncode VARCHAR( 100 ) NOT NULL,
                    partnercode VARCHAR( 100 ) DEFAULT NULL,
                    study VARCHAR( 100 ) DEFAULT NULL,
                    updated_at DATETIME DEFAULT NULL ,
                    created_at DATETIME DEFAULT NULL ,
                    UNIQUE (
                        id
                    )) ENGINE = MYISAM CHARACTER SET utf8 COLLATE utf8_general_ci;";
            } elseif(!PARTNER AND DIARYMODE) {
                $query="CREATE TABLE IF NOT EXISTS " . $DBname . ".".RESULTSTABLE." (
                    id INT NOT NULL AUTO_INCREMENT,
                    begansurveysmsintvar DATETIME DEFAULT NULL ,
                    vpncode VARCHAR( 100 ) NOT NULL,
                    study VARCHAR( 100 ) DEFAULT NULL,
                    iteration INT DEFAULT 1,
                    timestarted INT DEFAULT 0,
                    timefinished INT DEFAULT 0,
                    updated_at DATETIME DEFAULT NULL ,
                    created_at DATETIME DEFAULT NULL ,
                    UNIQUE (
                        id
                    )) ENGINE = MYISAM CHARACTER SET utf8 COLLATE utf8_general_ci;";				
            } elseif(!PARTNER AND !DIARYMODE) {
                $query="CREATE TABLE IF NOT EXISTS " . $DBname . ".".RESULTSTABLE." (
                    id INT NOT NULL AUTO_INCREMENT,
                    begansurveysmsintvar DATETIME DEFAULT NULL ,
                    vpncode VARCHAR( 100 ) NOT NULL,
                    study VARCHAR( 100 ) DEFAULT NULL,
                    updated_at DATETIME DEFAULT NULL ,
                    created_at DATETIME DEFAULT NULL ,
                    UNIQUE (
                        id
                    )) ENGINE = MYISAM CHARACTER SET utf8 COLLATE utf8_general_ci;";
            }

            mysql_query($query);
            $message="Ergebnistabelle wurde erstellt.<br />" . mysql_error();
            echo $message;
            // Mache ein Array, um dir benutze Variablennamen zu merken, damit es nicht zu Dopplungen komme
            $usedcolumnnames = array();

            // Wähle dir die Items aus der Itemtabelle aus, und übergehe Instruktionen
            $query=" SELECT * FROM " . ITEMSTABLE . " WHERE special = ''";
            $message="";
            $itemtable=mysql_query($query);

            // Erstelle je nach Variablentyp eine neue Variable in der Ergebnistabelle
            $query = "ALTER TABLE  `".RESULTSTABLE."` ";

            // Loope durch den ADD-Befehl für jede einzelne Variable
            while($row = mysql_fetch_assoc($itemtable)) {

                // Lasse Zeilen aus, die keinen Variablennamen definiert haben, oder bei denen einer verwendet wird, den es schon gibt.
                // FIX: Lasse weiterhin alle aus, die in special etwas anderes als ...start stehen haben!
                if (($row["variablenname"]!="") and !in_array($row["variablenname"],$usedcolumnnames)){
                    if ($row["typ"] == "offen" || $row['typ']=="pse") {
                        $query = $query . "ADD  `" . $row["variablenname"] . "` VARCHAR(50000), \n";
                        $used=$row["variablenname"];
                        array_push ($usedcolumnnames, $used);
					} elseif ($row["typ"] == "instruktion") {
                        $query = $query . "ADD  `" . $row["variablenname"] . "` VARCHAR(10), \n";
                        $used=$row["variablenname"];
                        array_push ($usedcolumnnames, $used);
					} elseif ($row["typ"] == "email") {
                        $query = $query . "ADD  `" . $row["variablenname"] . "` VARCHAR(500), \n";
                        $used=$row["variablenname"];
                        array_push ($usedcolumnnames, $used);
                    } elseif ($row["typ"] == "mmc") {
                        echo "multiple Antworten eingerichtet für: " . $row["variablenname"] . "(" . $row["antwortformatanzahl"] . ")<br />";
                        $query = $query . "ADD  `" . $row["variablenname"] . "` INT(100), \n";
                        $used=$row["variablenname"];
                        array_push ($usedcolumnnames, $used);
                        for ($i=1; $i <=$row["antwortformatanzahl"]; $i++) {
                            $mmcvarname = $row["variablenname"] . "mmcalt" . $i;
                            $query = $query . "ADD  `" . $mmcvarname . "` INT(100) NOT NULL DEFAULT '0', \n";
                            array_push ($usedcolumnnames, $mmcvarname);
                        }
                    } elseif ($row["typ"] == "ym") {
                        echo "multiple Antworten eingerichtet für: " . $row["variablenname"] . "(2)<br />";
                        $query = $query . "ADD  `" . $row["variablenname"] . "` INT(100), \n";
                        $used=$row["variablenname"];
                        array_push ($usedcolumnnames, $used);
                        $years = $row["variablenname"] . "mmcaltyears";
                        $query = $query . "ADD  `" . $years . "` INT(100) NOT NULL DEFAULT '0', \n";
                        array_push ($usedcolumnnames, $years);
                        $months = $row["variablenname"] . "mmcaltmonths";
                        $query = $query . "ADD  `" . $months . "` INT(100) NOT NULL DEFAULT '0', \n";
                        array_push ($usedcolumnnames, $months);
                    } elseif ($row["typ"] == "datepicker") {
                        echo "multiple Antworten eingerichtet für: " . $row["variablenname"] . "(3)<br />";
                        $query = $query . "ADD  `" . $row["variablenname"] . "` INT(100), \n";
                        $used=$row["variablenname"];
                        array_push ($usedcolumnnames, $used);
                        $years = $row["variablenname"] . "mmcaltyear";
                        $query = $query . "ADD  `" . $years . "` INT(100) NOT NULL DEFAULT '0', \n";
                        array_push ($usedcolumnnames, $years);
                        $months = $row["variablenname"] . "mmcaltmonth";
                        $query = $query . "ADD  `" . $months . "` INT(100) NOT NULL DEFAULT '0', \n";
                        array_push ($usedcolumnnames, $months);
                        $day = $row["variablenname"] . "mmcaltday";
                        $query = $query . "ADD  `" . $day . "` INT(100) NOT NULL DEFAULT '0', \n";
                        array_push ($usedcolumnnames, $day);
                    } else {
                        $query = $query . "ADD  `" . $row["variablenname"] . "` INT(100), \n";
                        $used=$row["variablenname"];
                        array_push ($usedcolumnnames, $used);
                    }
                } else {
                    $message = $message . "\n" . $row["id"] . " hat keinen oder einen schon benutzten Variablennamen definiert, und konnte daher nicht in die Ergebnistabelle übernommen werden.<br />";

                }
            }


            // Füge noch die Standardvariablen an, die ans Ende der Tabelle kommen
            $query = $query . "ADD  `" . endedsurveysmsintvar . "` DATETIME DEFAULT NULL";

            // echo $query;
            if (mysql_query($query)) {
                $message = $message . "Variablen aus Item-Tabelle wurden erstellt.<br />";
            } else {
                $message = $message . "Variablen aus Item-Tabelle konnten nicht erstellt werden: " . mysql_error() . "<br />";
            }

        } else {
            $message = $message . "Ey, du Pansen! Es gibt doch noch nicht einmal eine item-Tabelle!";
        }
        echo $message;
        resetitemdisplaytable();
    }
}

function get_all_emails() {
    $query = "SELECT * FROM ".EMAILSTABLE;
    $results = mysql_query( $query ) or die( mysql_error() );
    $outarr = array();
    while( $row = mysql_fetch_assoc($results) ) {
        array_push($outarr,$row);
    } 
    return $outarr;
}

function createvpndatatab() {
    if(table_exists(VPNDATATABLE,$DBname)) {
        $message = "Eine Vpndatatabelle existiert bereits... :-/";
        echo $message;
    } else {
        if(PARTNER) {
            $query =
                "CREATE TABLE IF NOT EXISTS ". $DBname .".".VPNDATATABLE." ( 
                    id INT(11) NOT NULL AUTO_INCREMENT, 
                    vpncode VARCHAR(100) NOT NULL, 
                    partnercode VARCHAR(100) DEFAULT NULL, 
                    email VARCHAR(100) DEFAULT NULL, 
                    vpntype INT(10) DEFAULT 1, 
                    study VARCHAR(100), 
                    UNIQUE(id)) 
					ENGINE = MYISAM CHARACTER SET utf8 COLLATE utf8_general_ci;";
			$mailuser = "INSERT INTO ".VPNDATATABLE." (vpncode,study) VALUES ('mailman','none')";

        } elseif(!PARTNER) {
            $query =
                "CREATE TABLE IF NOT EXISTS ". $DBname .".".VPNDATATABLE." ( 
                    id INT(11) NOT NULL AUTO_INCREMENT, 
                    vpncode VARCHAR(100) NOT NULL, 
                    email VARCHAR(100) DEFAULT NULL, 
                    study VARCHAR(100)
                    UNIQUE(id)) 
                    ENGINE = MYISAM CHARACTER SET utf8 COLLATE utf8_general_ci;";
			$mailuser = "INSERT INTO ".VPNDATATABLE." (vpncode,study) VALUES ('mailman','none')";
        }
        mysql_query($query) or die(mysql_error());
        mysql_query($mailuser) or die(mysql_error());
	}
}


function createItemsTable() {
$query="CREATE  TABLE IF NOT EXISTS `".ITEMSTABLE."` (
  `id` INT(11) NOT NULL ,
  `variablenname` VARCHAR(100) NOT NULL ,
  `wortlaut` TEXT NOT NULL ,
  `altwortlautbasedon` VARCHAR(150) NOT NULL ,
  `altwortlaut` TEXT NOT NULL ,
  `typ` VARCHAR(100) NOT NULL ,
  `antwortformatanzahl` INT(100) NOT NULL ,
  `ratinguntererpol` TEXT NOT NULL ,
  `ratingobererpol` TEXT NOT NULL ,
  `MCalt1` TEXT NOT NULL ,
  `MCalt2` TEXT NOT NULL ,
  `MCalt3` TEXT NOT NULL ,
  `MCalt4` TEXT NOT NULL ,
  `MCalt5` TEXT NOT NULL ,
  `MCalt6` TEXT NOT NULL ,
  `MCalt7` TEXT NOT NULL ,
  `MCalt8` TEXT NOT NULL ,
  `MCalt9` TEXT NOT NULL ,
  `MCalt10` TEXT NOT NULL ,
  `MCalt11` TEXT NOT NULL ,
  `MCalt12` TEXT NOT NULL ,
  `MCalt13` TEXT NOT NULL ,
  `MCalt14` TEXT NOT NULL ,
  `Teil` VARCHAR(255) NOT NULL ,
  `relevant` CHAR(1) NOT NULL ,
  `skipif` TEXT NOT NULL ,
  `special` VARCHAR(100) NOT NULL ,
  `rand` VARCHAR(10) NOT NULL ,
  `study` VARCHAR(100) NOT NULL ,
  PRIMARY KEY (`id`) )
ENGINE = MyISAM
DEFAULT CHARACTER SET = utf8";
	mysql_query($query) or die( mysql_error() );
}

function createstudiestable() {
	$query = "CREATE TABLE IF NOT EXISTS `".STUDIESTABLE."` (
		`id` int(11) NOT NULL AUTO_INCREMENT,
		`order` int(10) DEFAULT 0,
		`name` varchar(100) NOT NULL,
		`loop` int(2) DEFAULT 0,
		`iterations` int(20) DEFAULT 1,
		`max_attempts` int(20) UNSIGNED DEFAULT 30,
		`loopemail` int(2) DEFAULT 0,
		`loopemail_id` int(20) DEFAULT NULL,
		`postemail` int(2) DEFAULT 0,
		`postemail_id` int(20) DEFAULT NULL,
		`delta` int(50) DEFAULT NULL,
		`skipif` varchar(50) DEFAULT NULL,	
		PRIMARY KEY (`id`)
	) ENGINE=MyISAM DEFAULT CHARSET=utf8;";
	mysql_query($query) or die( mysql_error() );
}

function get_study($id) {    /* generic function to look up which study an item is associated with; $id is the unique integer id of the items; */
    $query = "SELECT study FROM ".ITEMSTABLE." WHERE id='".$id."';";
    $result = mysql_query($query);
    $result = mysql_fetch_assoc($result);
    foreach ($result as $item) {
        return $item;
    }
}

function deletevpndatatab() {
    if (isset($_GET['confirm'])) $confirm = $_GET['confirm'];
    if ($confirm=="576879ccc") {
        $query= "DROP TABLE ".VPNDATATABLE;
        mysql_query($query);
        $message="VPNdatatabelle wurde gelöscht.<br />" . mysql_error();
    } else {
        $message="Willst du wirklich die VPNDatatabelle löschen? <a class=\"adminmessage\" href=\"index.php?action=deletevpndatatab&confirm=576879ccc\">LÖSCHEN</a> / <a class=\"adminmessage\" href=\"index.php\">Neee, doch nich...</a><br />";
    }
    echo $message;
}

function sncreateresulttab() {
    //Prüfe, ob Tabelle existiert
    if (table_exists(SNRESULTSTABLE, $DBname)) {
        // Wenn ja, dann breche ab.
        $message="Eine SN-Ergebnistabelle existiert bereits.<br />Eine neue kann nur erstellt werden, wenn vorher die alte gelöscht wird.<br />Vorsicht! Dabei gehen alle bisher erhobenen Daten verloren.";
        echo $message;
    } else {
        // Prüfe, ob es überhaupt schon eine item-Tabelle gibt
        if (table_exists(ITEMSTABLE, $DBname)) {
            $query="CREATE TABLE IF NOT EXISTS " . $DBname . ".".SNRESULTSTABLE." (
                id INT(11) NOT NULL AUTO_INCREMENT,
                begansurveysmsintvar DATETIME NOT NULL ,
                vpncode VARCHAR( 100 ) NOT NULL ,
                wave INT(100) NOT NULL ,
                person VARCHAR( 100 ) NOT NULL ,
                UNIQUE (
                    id
                )) ENGINE = MYISAM CHARACTER SET utf8 COLLATE utf8_general_ci;";
            mysql_query($query);
            $message="SN-Ergebnistabelle wurde erstellt.<br />" . mysql_error();
            echo $message;
            // Mache ein Array, um dir benutze Variablennamen zu merken, damit es nicht zu Dopplungen komme
            $usedcolumnnames = array();

            // Wähle dir die Items aus der Itemtabelle aus, und übergehe Instruktionen
            $query=" SELECT * FROM ".ITEMSTABLE." WHERE special!=''";
            $message="";
            $itemtable=mysql_query($query);

            // Erstelle je nach Variablentyp eine neue Variable in der Ergebnistabelle
            $query = "ALTER TABLE  `".SNRESULTSTABLE."` ";

            // Loope durch den ADD-Befehl für jede einzelne Variable
            while($row = mysql_fetch_assoc($itemtable)) {

                // Lasse Zeilen aus, die keinen Variablennamen definiert haben, oder bei denen einer verwendet wird, den es schon gibt.
                // FIX:

                if (($row["variablenname"]!="") and !in_array($row["variablenname"],$usedcolumnnames)){
                    if ($row["typ"] == "offen" OR $row["typ"] == "snpartner") {
                        $query = $query . "ADD  `" . $row["variablenname"] . "` VARCHAR(" . $row["antwortformatanzahl"] . ") NOT NULL, \n";
                        $used=$row["variablenname"];
                        array_push ($usedcolumnnames, $used);
                    } elseif ($row["typ"] == "instruktion") {
                        // Auslassen					
                    } else {
                        $query = $query . "ADD  `" . $row["variablenname"] . "` INT(100) DEFAULT NULL, \n";
                        $used=$row["variablenname"];
                        array_push ($usedcolumnnames, $used);
                    }
                } else {
                    $message = $message . "\n" . $row["id"] . " hat keinen oder einen schon benutzten Variablennamen definiert, und konnte daher nicht in die Ergebnistabelle übernommen werden.<br />";

                }
            }

            // Füge noch die Standardvariablen an, die ans Ende der Tabelle kommen
            $query = $query . "ADD  `" . endedsurveysmsintvar . "` DATE NOT NULL";

            // echo $query;
            if (mysql_query($query)) {
                $message = $message . "Variablen aus Item-Tabelle wurden in snresults erstellt.<br />";
            } else {
                $message = $message . "Variablen aus Item-Tabelle konnten nicht in snresults erstellt werden: " . mysql_error();
            }

        } else {
            $message = $message . "Ey, du Pansen! Es gibt doch noch nicht einmal eine item-Tabelle!";
        }
        echo $message;
    }
}

function sndeleteresults() {
    if (isset($_GET['confirm'])) $confirm = $_GET['confirm'];
    if ($confirm=="576879ccc") {
        $query= "DROP TABLE ".SNRESULTSTABLE;
        mysql_query($query);
        $message="SN-Ergebnistabelle wurde gelöscht.<br />" . mysql_error();
    } else {
        $message="Willst du wirklich die gesamte SN-Ergebnistabelle löschen? <a class=\"adminmessage\" href=\"index.php?action=sndeleteresults&confirm=576879ccc\">LÖSCHEN</a> / <a class=\"adminmessage\" href=\"index.php\">Bloß nicht!</a><br />";
    }
    echo $message;
}

function variable_exists($table, $database, $variable) {
    if (table_exists($table, $database)) {
        $query ="DESCRIBE $table";
        $tablenames = mysql_query($query);
        while($i = mysql_fetch_assoc($tablenames)) {
            $variablennamen[] = $i['Field'];
        }
        if (in_array($variable, $variablennamen)) {
            unset($meta);
            return true;
        } else {
            unset($meta);
            return false;
        }
    } else {
        return false;
    }
}

function howmanymcs($table, $database) {
    // Sagt, wie viele Multiple Choice-Optionen es bis jetzt in der Tabelle gibt
    // Nimm dir die Tabelle
    $query ="DESCRIBE $table";
    $tablenames = mysql_query($query);
    while($i = mysql_fetch_assoc($tablenames)) {
        $variablennamen[] = $i['Field'];
    }
    for ($counter=1; $counter <= 100; $counter++) {
        // Es werden theoretisch nicht mehr als 100 MC-Alternativen zugelassen
        $variable = "MCalt".$counter;
        if (!in_array($variable, $variablennamen)) {
            return $counter;
        }
    }
}

function createitemdisplaytable() {
    $query ="CREATE TABLE IF NOT EXISTS `" . ITEMDISPLAYTABLE . "` (
  `variablenname` varchar(100) NOT NULL,
  `vpncode` varchar(100) NOT NULL,
  `displaycount` int(2) NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`variablenname`,`vpncode`),
  UNIQUE KEY `varcre` (`variablenname`,`created_at`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;";
    mysql_query($query);
}


function resetitemdisplaytable() {
    if (table_exists(ITEMDISPLAYTABLE, $DBname)) {
        $query="truncate " . ITEMDISPLAYTABLE .";";
        mysql_query($query);
        $message="existierende Itemdisplay-Tabelle wurde geleert.<br />" . mysql_error();
    } else {
        createitemdisplaytable();
        $message="Eine leere Itemdisplay-Tabelle wurde angelegt.<br />" . mysql_error();
    }
    echo $message;
}

function createtimestable() {
    $query="CREATE TABLE IF NOT EXISTS " . $DBname . ".".TIMESTABLE." (
        id INT NOT NULL AUTO_INCREMENT,
        date_added DATETIME DEFAULT NULL,
        starttime INT NOT NULL,
        endtime INT NOT NULL,
        UNIQUE (
            id
        )) ENGINE = MYISAM CHARACTER SET utf8 COLLATE utf8_general_ci;";
    mysql_query($query) or die( mysql_error() );
}

function createsubstable() {
    $query="CREATE TABLE IF NOT EXISTS " . $DBname . ".".SUBSTABLE." (
        id INT NOT NULL AUTO_INCREMENT,
        `mode` INT DEFAULT 0,
        `key` VARCHAR(100),
                `value` VARCHAR(100),
            UNIQUE (
                id
            )) ENGINE = MYISAM CHARACTER SET utf8 COLLATE utf8_general_ci;";
    mysql_query($query) or die( mysql_error() );
}

function createemailtables() {
    $query="CREATE TABLE IF NOT EXISTS " . $DBname . ".".EMAILSTABLE." (
        id INT NOT NULL AUTO_INCREMENT,
        name VARCHAR(30) NOT NULL,
        subject VARCHAR(100) NOT NULL,
        body VARCHAR(1000) NOT NULL,
        delta BIGINT UNSIGNED NOT NULL DEFAULT 0,
        abstime BIGINT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE (
            id
        )) ENGINE = MYISAM CHARACTER SET utf8 COLLATE utf8_general_ci;";
    mysql_query($query) or die( mysql_error() );

    $query="CREATE TABLE IF NOT EXISTS " . $DBname . ".".MESSAGEQUEUE." (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email_id INT NOT NULL,
		vpncode VARCHAR(50) NOT NULL,
        email_address VARCHAR(50) NOT NULL,
        send_time INT DEFAULT 0,
        UNIQUE (
            id
        )) ENGINE = MYISAM CHARACTER SET utf8 COLLATE utf8_general_ci;";
    mysql_query($query) or die( mysql_error() );
}

function render_add_new_time() {
    echo "<p style=\"background: #CCCCCC;\"><strong>Add new Edit-Times</strong></p>";
    echo "<form method=\"POST\" action=\"edittimes.php\"><table class=\"editstudies\">";
    echo "<th>Start-Time</th><th>End-Time</th>";

    echo "<tr> <td><select name=\"starthour\">";
    for( $i=0; $i < 24; $i++ ) {
        $value = sprintf("%02d",$i);
        echo "<option value=\"".$value."\">".$value."</option>";
    }
    echo "</select>";

    echo "<select name=\"startminute\">";
    for( $i=0; $i < 60; $i++ ) {
        $value = sprintf("%02d",$i);
        echo "<option value=\"".$value."\">".$value."</option>";
    }
    echo "</select>";

    echo "<select name=\"startseconds\">";
    for( $i=0; $i < 60; $i++ ) {
        $value = sprintf("%02d",$i);
        echo "<option value=\"".$value."\">".$value."</option>";
    }
    echo "</select><br />Hour, Minute, Seconds";

    echo "<td><select name=\"endhour\">";
    for( $i=0; $i < 24; $i++ ) {
        $value = sprintf("%02d",$i);
        echo "<option value=\"".$value."\">".$value."</option>";
    }
    echo "</select>";
    echo "<select name=\"endminute\">";
    for( $i=0; $i < 60; $i++ ) {
        $value = sprintf("%02d",$i);
        echo "<option value=\"".$value."\">".$value."</option>";
    }
    echo "</select>";

    echo "<select name=\"endseconds\">";
    for( $i=0; $i < 60; $i++ ) {
        $value = sprintf("%02d",$i);
        echo "<option value=\"".$value."\">".$value."</option>";
    }
    echo "</select><br />Hour, Minute, Seconds";

    echo "</table><input type=\"submit\" value=\"Enter\"/><p style=\"background: #ffdddd;\" >Please make sure that End-Time > Start-Time :-)</p></form>";
}

function render_add_new_email() {
    echo "<p style=\"background: #CCCCCC;\"><strong>Add new Email</strong></p>";
    echo "<form method=\"POST\" action=\"editemails.php\"><table class=\"editstudies\">";
    echo "<th>Name</th><th>Time of Day</th><th>DeltaTime</th><th>Subject</th><th>Body</th>";

    echo "<tr>";
    echo "<td><input type='text' size='10' maxlength='30' name='addemailname' /></td>";

	echo "<td>";
	echo "<select name=\"abshour\">";
    for( $i=0; $i < 24; $i++ ) {
        $value = sprintf("%02d",$i);
        echo "<option value=\"".$value."\">".$value."</option>";
    }
    echo "</select>";

    echo "<select name=\"absminute\">";
    for( $i=0; $i < 60; $i++ ) {
        $value = sprintf("%02d",$i);
        echo "<option value=\"".$value."\">".$value."</option>";
    }
    echo "</select><br/>Hour, Minute</td>";

    echo "<td><select name=\"deltaday\">";
    for( $i=0; $i < 365; $i++ ) {
        echo "<option value=\"".$i."\">".$i."</option>";
    }
    echo "</select>Days";

    echo "<td><input type=\"text\" size=\"35\" maxlength=\"50\" name=\"addemailsubject\" /></td>";
    echo "<td><textarea cols=\"28\" rows=\"10\" name=\"addemailbody\"></textarea></td></tr>";

    echo "</table><input type=\"submit\" value=\"Enter\" /></form>";
}

function render_add_new_subst() {
    echo "<p style=\"background: #CCCCCC;\"><strong>Add new Substitution</strong></p>";
    echo "<form method=\"POST\" action=\"editsubstitutions.php\"><table class=\"editstudies\">";
    echo "<th>Iteration</th><th>Key</th><th>Value</th>";

    echo "<tr>";
    echo "<td><input type=\"text\" size=\"2\" maxlength=\"11\" name=\"addsubstmode\" /></td>";

    echo "<td><input type=\"text\" size=\"35\" maxlength=\"50\" name=\"addsubstkey\" /></td>";
    echo "<td><input type=\"text\" size=\"35\" maxlength=\"50\" name=\"addsubstvalue\" /></td>";

    echo "</table><input type=\"submit\" value=\"Enter\" /></form>";
}


function time_exists($starttime,$endtime) {
    $query = "SELECT id FROM ".TIMESTABLE." WHERE (starttime=".$starttime." AND endtime=".$endtime.") OR (".$starttime." BETWEEN ".TIMESTABLE.".starttime AND ".TIMESTABLE.".endtime) OR (".$endtime." BETWEEN ".TIMESTABLE.".starttime AND ".TIMESTABLE.".endtime);";
    $result = mysql_query($query) or die( mysql_error() );
    if( mysql_numrows($result) > 0 ) {
        return true;
    } else {
        return false;
    }
}

function create_table($table) {
	switch( $table ) {
	case ADMINTABLE:
		setupadmin();
		break;
	case EMAILSTABLE:
		createemailtables();
		break;
	case ITEMDISPLAYTABLE:
		createitemdisplaytable();
		break;
	case ITEMSTABLE:
		createItemsTable();
		break;
	case RESULTSTABLE:
		createresulttab();
		break;
	case SNRESULTSTABLE:
		sncreateresulttab();
		break;
	case STUDIESTABLE:
		createstudiestable();
		break;
	case SUBSTABLE:
		createsubstable();
		break;
	case TIMESTABLE:
		createtimestable();
		break;
	case VPNDATATABLE:
		createvpndatatab();
		break;
	}	
}
?>