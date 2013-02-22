<?php

/* session_start(); */
header ('Content-type: text/html; charset=utf-8');

// Settings einlesen
require ('includes/settings.php');

// MySQL verbinden und Datenbank auswählen (Tabelle noch nicht)
require ('includes/mysql.php');

require('includes/variables.php');	

// Functions einbinden
require ('includes/functions.php');

// run our 'cron' jobs
require('includes/schedule.php');

if(OUTBUFFER) {
	ob_start();
}

?><!DOCTYPE html>

<html>
<head> 
        <title><?php echo TITLE ?></title>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
        <meta name="description" content="<?php echo DESCRIPTION ?>"/>
        <meta name="keywords" content="<?php echo KEYWORDS ?>"/>
        <meta name="author" content="<?php echo AUTHOR ?>"/>
        <meta name="copyright" content="<?php echo COPYRIGHT ?>"/>

<?php
// styles
require ('style.php');
// Scripts
require ('scripts.php');
// color variables from admin-backend
echo '<style type="text/css">';
if( PRIMARY_COLOR != "" ) {
	echo '.primary-color { background-color: '.PRIMARY_COLOR.';}' . PHP_EOL;
}
if( SECONDARY_COLOR != "" ) {
	echo '.secondary-color { background-color: '.SECONDARY_COLOR.';}' . PHP_EOL;
}
if( ODD_COLOR != "" ) {
	echo '.odd, .odd-repeat { background-color: '.ODD_COLOR.';}' . PHP_EOL;
}
if( EVEN_COLOR != "" ) {
	echo '.even, .even-repeat { background-color: '.EVEN_COLOR.';}' . PHP_EOL;
}
echo '</style>';
?>


</head>
<body>

<?php 
$vpncode = getvpncode();
?>
