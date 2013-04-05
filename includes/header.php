<?php

/* session_start(); */
header ('Content-type: text/html; charset=utf-8');

// Settings einlesen
require ('includes/settings.php');
require('includes/variables.php');	

// Functions einbinden
require ('includes/functions.php');

if(OUTBUFFER) {
	ob_start();
}

$vpncode = getvpncode();
?><!DOCTYPE html>

<html>
<head> 
        <title><?php echo TITLE ?></title>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
        <meta name="description" content="<?php echo DESCRIPTION ?>"/>
		<link rel="stylesheet" type="text/css" href="css/bootstrap.min.css" />
		<link rel="stylesheet" href="css/screen.css" type="text/css" media="screen" />
		<link rel="stylesheet" href="css/debug.css" type="text/css" media="screen" />
		<link rel="stylesheet" href="css/backend.css" type="text/css" media="screen" />
        <link rel="stylesheet" href="css/font-awesome.min.css">
		<!--[if IE 7]>
		<link rel="stylesheet" href="css/font-awesome-ie7.min.css">
		<![endif]-->
		
</head>
<body>
	<header class="study-header">
		
	</header>
    <!--[if lt IE 7]>
        <p class="chromeframe">You are using an <strong>outdated</strong> browser. Please <a href="http://browsehappy.com/">upgrade your browser</a> or <a href="http://www.google.com/chromeframe/?redirect=true">activate Google Chrome Frame</a> to improve your experience.</p>
    <![endif]-->

    <!-- This code is taken from http://twitter.github.com/bootstrap/examples/hero.html -->

	<div class="maincontent container clearfix">
		<div id="top">
		    <div id="sidebar">
		        <img src="img/<?=LOGO?>">
			   <a href="index.php">Zurück</a>
		    </div>
		</div>

		<div id="main">
		    <div id="title">
		        <? echo "<h1>" . TITLE . "</h1>";
		        echo DESCRIPTION;
		        ?>
		    </div>
		    <div class="clearer"></div>
