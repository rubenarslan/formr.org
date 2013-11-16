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
		<link href="//netdna.bootstrapcdn.com/twitter-bootstrap/3.0.2/css/bootstrap.min.css" rel="stylesheet">
		<link href="//netdna.bootstrapcdn.com/font-awesome/4.0.3/css/font-awesome.min.css" rel="stylesheet">
		<link rel="stylesheet" type="text/css" href="//cdn.jsdelivr.net/select2/3.4.5/select2-bootstrap.css">
		
	<?php
	else:
	?>
		<link rel="stylesheet" type="text/css" href="<?=WEBROOT?>assets/bower_components/bootstrap/dist/css/bootstrap.css" />
        <link rel="stylesheet" href="<?=WEBROOT?>assets/bower_components/font-awesome/css/font-awesome.css">
		<link rel="stylesheet" type="text/css" href="<?=WEBROOT?>assets/bower_components/select2/select2.css">
		
	<?php
	endif;
	?>
		<link rel="stylesheet" href="<?=WEBROOT?>assets/main.css" type="text/css" media="screen">
		<?php echo isset($css)?$css:'' ?>
		
		
		<?php 
		if(ONLINE):
		?>
			<script src="//ajax.googleapis.com/ajax/libs/jquery/2.0.3/jquery.js"></script>
			<script src="//netdna.bootstrapcdn.com/twitter-bootstrap/3.0.2/js/bootstrap.min.js"></script>
			<script src="<?=WEBROOT?>assets/modernizr-2.6.2-respond-1.1.0.min.js"></script>
			<script src="//cdn.jsdelivr.net/webshim/1.11.3/polyfiller.js"></script>
			<script type="text/javascript" src="//cdn.jsdelivr.net/select2/3.4.5/select2.min.js"></script>
			
		<?php
		else:
		?>
			<script src="<?=WEBROOT?>assets/bower_components/jquery/jquery.js"></script>
			<script src="<?=WEBROOT?>assets/bower_components/bootstrap/dist/js/bootstrap.js"></script>
			<script src="<?=WEBROOT?>assets/modernizr-2.6.2-respond-1.1.0.min.js"></script>
			<script src="<?=WEBROOT?>assets/bower_components/webshim/demos/js-webshim/minified/polyfiller.js"></script>
			<script type="text/javascript" src="<?=WEBROOT?>assets/bower_components/select2/select2.js"></script>
			
		<?php
		endif;
		?>
		<script src="<?=WEBROOT?>assets/main.js"></script>
		
		<?php echo isset($js)?$js:'' ?>
</head>
<body>
	<?php if(isset($survey_view)): ?>
	<div class="container">
	<?php else: ?>
	<div class="container pull-left">
	<?php endif; ?>
		
		<div class="row">
			<header class="col-lg-12 study-header">
		
			</header>
		</div>
    <!--[if lt IE 7]>
        <p class="alert chromeframe">
	 <button type="button" class="close" data-dismiss="alert">&times;</button>
       You are using an <strong>outdated</strong> browser. Please <a href="http://browsehappy.com/">upgrade your browser</a> or <a href="http://www.google.com/chromeframe/?redirect=true">activate Google Chrome Frame</a> to improve your experience.</p>
    <![endif]-->
