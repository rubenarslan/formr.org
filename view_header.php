<?php
header ('Content-type: text/html; charset=utf-8');
?><!DOCTYPE html>

<html>
<head> 
        <title><?php echo isset($title) ? $title : 'Studien'; ?></title>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>

		<link rel="stylesheet" type="text/css" href="<?=WEBROOT?>css/bootstrap.min.css" />
        <link rel="stylesheet" href="<?=WEBROOT?>css/font-awesome.min.css">
		<!--[if IE 7]>
		<link rel="stylesheet" href="css/font-awesome-ie7.min.css">
		<![endif]-->
		<link rel="stylesheet" href="<?=WEBROOT?>css/main.css" type="text/css" media="screen" />
<?php // fixme: enable this again when in production, annoying when on a slow connection

	//	<script src="//ajax.googleapis.com/ajax/libs/jquery/1.9.1/jquery.js"></script>
?>		<script>window.jQuery || document.write('<script src="<?=WEBROOT?>js/vendor/jquery-1.9.1.min.js"><\/script>')</script>
		<script src="<?=WEBROOT?>js/vendor/bootstrap.min.js"></script>
		<script src="<?=WEBROOT?>js/vendor/modernizr-2.6.2-respond-1.1.0.min.js"></script>
		<script src="<?=WEBROOT?>js/vendor/js-webshim/minified/polyfiller.js"></script>
		<script src="<?=WEBROOT?>js/main.js"></script>
</head>
<body>
	<header class="study-header">
		
	</header>
    <!--[if lt IE 7]>
        <p class="chromeframe">You are using an <strong>outdated</strong> browser. Please <a href="http://browsehappy.com/">upgrade your browser</a> or <a href="http://www.google.com/chromeframe/?redirect=true">activate Google Chrome Frame</a> to improve your experience.</p>
    <![endif]-->

	<div class="maincontent container clearfix">
