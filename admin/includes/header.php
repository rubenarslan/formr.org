<?php
header ('Content-type: text/html; charset=utf-8');

// Settings einlesen
require_once '../includes/settings.php';
require_once '../includes/variables.php';
require_once '../includes/functions.php';
require_once 'functions.php';
?><!DOCTYPE html>

<html>
<head> 
        <title><?php echo TITLE ?></title>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
        <meta name="description" content="<?php echo DESCRIPTION ?>"/>
		<link rel="stylesheet" type="text/css" href="../css/bootstrap.min.css" />
        <link rel="stylesheet" href="../css/font-awesome.min.css">
		<!--[if IE 7]>
		<link rel="stylesheet" href="../css/font-awesome-ie7.min.css">
		<![endif]-->
		<link rel="stylesheet" href="../css/screen.css" type="text/css" media="screen" />
		<link rel="stylesheet" href="../css/main.css" type="text/css" media="screen" />
		<script src="//ajax.googleapis.com/ajax/libs/jquery/1.9.1/jquery.js"></script>
		<script>window.jQuery || document.write('<script src="js/vendor/jquery-1.9.1.min.js"><\/script>')</script>
		<script src="../js/vendor/bootstrap.min.js"></script>
		<script src="../js/vendor/modernizr-2.6.2-respond-1.1.0.min.js"></script>
		<script src="../js/vendor/js-webshim/minified/polyfiller.js"></script>
		<script src="../js/main.js"></script>
</head>
<body>
	<header class="study-header">
		
	</header>
    <!--[if lt IE 7]>
        <p class="chromeframe">You are using an <strong>outdated</strong> browser. Please <a href="http://browsehappy.com/">upgrade your browser</a> or <a href="http://www.google.com/chromeframe/?redirect=true">activate Google Chrome Frame</a> to improve your experience.</p>
    <![endif]-->

	<div class="maincontent container clearfix">
		<div class="row-fluid">
		    <div id="span12">
		        <? echo "<h1>" . TITLE . "</h1>";
		        echo DESCRIPTION;
		        ?>
		    </div>
		</div>
		<div class="row-fluid">
			<div class="span10">
