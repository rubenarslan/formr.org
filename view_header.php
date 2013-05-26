<?php
header ('Content-type: text/html; charset=utf-8');
?><!DOCTYPE html>

<html>
<head> 
        <title><?php echo isset($title) ? $title : 'Studie'; ?></title>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>

	<?php 
	if(ONLINE):
	?>
		<link href="//netdna.bootstrapcdn.com/twitter-bootstrap/2.3.1/css/bootstrap-combined.no-icons.min.css" rel="stylesheet">
		<link href="//netdna.bootstrapcdn.com/font-awesome/3.1.1/css/font-awesome.min.css" rel="stylesheet">
		<script src="//ajax.googleapis.com/ajax/libs/jquery/1.9.1/jquery.js"></script>
		<script src="//netdna.bootstrapcdn.com/twitter-bootstrap/2.3.1/js/bootstrap.min.js"></script>
	<?php
	else:
	?>
		<script src="<?=WEBROOT?>js/vendor/jquery-1.9.1.min.js"></script>
		<link rel="stylesheet" type="text/css" href="<?=WEBROOT?>css/bootstrap.min.css" />
        <link rel="stylesheet" href="<?=WEBROOT?>css/font-awesome.min.css">
		<script src="<?=WEBROOT?>js/vendor/bootstrap.min.js"></script>
	<?php
	endif;
	?>		
        <!--[if IE 7]>
		<link rel="stylesheet" href="css/font-awesome-ie7.min.css">
		<![endif]-->
		<link rel="stylesheet" href="<?=WEBROOT?>css/main.css" type="text/css" media="screen">
		<script src="<?=WEBROOT?>js/vendor/modernizr-2.6.2-respond-1.1.0.min.js"></script>
		<script src="<?=WEBROOT?>js/vendor/js-webshim/minified/polyfiller.js"></script>
		<link rel="stylesheet" type="text/css" href="<?=WEBROOT?>js/vendor/select2/select2.css">
		<script type="text/javascript" src="<?=WEBROOT?>js/vendor/select2/select2.js"></script>
		
		<script src="<?=WEBROOT?>js/main.js"></script>
		<?php echo isset($head)?$head:'' ?>
</head>
<body>
	<header class="study-header">
		
	</header>
    <!--[if lt IE 7]>
        <p class="chromeframe">You are using an <strong>outdated</strong> browser. Please <a href="http://browsehappy.com/">upgrade your browser</a> or <a href="http://www.google.com/chromeframe/?redirect=true">activate Google Chrome Frame</a> to improve your experience.</p>
    <![endif]-->

	<div class="maincontent clearfix">
