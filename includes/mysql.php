<?php
// Benötigt $DBhost, $DBuser, $DBpass und $DBname aus Settings.php!
mysql_connect($DBhost,$DBuser,$DBpass) or die("Datenbank-Verbindung fehlgeschlagen. Bitte versuchen Sie es noch einmal.");
@mysql_select_db("$DBname") or die("Datenbank-Auswahl fehlgeschlagen. Bitte versuchen Sie es noch einmal.");
mysql_query("set names 'utf8';");
# ALL TABLES MUST BE utf8_general_ci in SQL!

