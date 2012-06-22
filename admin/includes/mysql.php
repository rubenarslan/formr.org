<?php

if ($usesql=="yes") {
// Benötigt $DBhost, $DBuser, $DBpass und $DBName aus Settings.php!
mysql_connect($DBhost,$DBuser,$DBpass) or die("Datenbank-Verbindung fehlgeschlagen. Bitte versuchen Sie es noch einmal.");
@mysql_select_db("$DBName") or die("Datenbank-Auswahl fehlgeschlagen. Bitte versuchen Sie es noch einmal.");
mysql_query("set names 'utf8';");
} else {
	echo "<!-- kein MySQL -->";
}
?>