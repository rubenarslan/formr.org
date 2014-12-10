<?php header('Content-type: text/html; charset=utf-8');
?><!DOCTYPE html>

<html>
	<head> 
        <title><?php echo $site->makeTitle(); ?></title>
        <meta charset="utf-8"> 
		<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

		<?php if (DEBUG < 1): ?>
			<link href="//netdna.bootstrapcdn.com/bootstrap/3.2.0/css/bootstrap.min.css" rel="stylesheet">
			<link href="//netdna.bootstrapcdn.com/font-awesome/4.1.0/css/font-awesome.min.css" rel="stylesheet">
			<!-- <link rel="stylesheet" type="text/css" href="//cdn.jsdelivr.net/select2/3.5.0/select2.css"> -->
			<link rel="stylesheet" type="text/css" href="<?= WEBROOT ?>assets/bower_components/select2/select2.css">

		<?php else: ?>
			<link rel="stylesheet" type="text/css" href="<?= WEBROOT ?>assets/bower_components/bootstrap/dist/css/bootstrap.css" />
	        <link rel="stylesheet" href="<?= WEBROOT ?>assets/bower_components/font-awesome/css/font-awesome.css">
			<link rel="stylesheet" type="text/css" href="<?= WEBROOT ?>assets/bower_components/select2/select2.css">
		<?php endif; ?>
		<link rel="stylesheet" href="<?= WEBROOT ?>assets/highlight/styles/idea.css" type="text/css" media="screen">
		<link rel="stylesheet" href="<?= WEBROOT ?>assets/main.css" type="text/css" media="screen">
		<?php echo isset($css) ? $css : '' ?>

		<?php if (DEBUG < 1): ?>
			<script src="//ajax.googleapis.com/ajax/libs/jquery/2.1.1/jquery.min.js"></script>
			<script src="//netdna.bootstrapcdn.com/bootstrap/3.2.0/js/bootstrap.min.js"></script>
			<script src="//cdnjs.cloudflare.com/ajax/libs/fastclick/1.0.2/fastclick.min.js"></script>
			<script src="<?= WEBROOT ?>assets/bower_components/webshim/js-webshim/minified/polyfiller.js"></script>
			<script type="text/javascript" src="<?= WEBROOT ?>assets/bower_components/select2/select2.js"></script>
			<?php
//				<script src="//cdn.jsdelivr.net/webshim/1.14.3/polyfiller.js"></script>
//				<script type="text/javascript" src="//cdn.jsdelivr.net/select2/3.5.0/select2.min.js"></script>
			?>
			<script>
				(function(i, s, o, g, r, a, m) {
					i['GoogleAnalyticsObject'] = r;
					i[r] = i[r] || function() {
						(i[r].q = i[r].q || []).push(arguments)
					}, i[r].l = 1 * new Date();
					a = s.createElement(o),
							m = s.getElementsByTagName(o)[0];
					a.async = 1;
					a.src = g;
					m.parentNode.insertBefore(a, m)
				})(window, document, 'script', '//www.google-analytics.com/analytics.js', 'ga');

				ga('create', 'UA-45924096-1', 'formr.org');
				ga('send', 'pageview');

			</script>

			<?php if ($site->inAdminArea()): ?>
				<script type="text/javascript" src="<?= WEBROOT ?>assets/bower_components/ace-builds/src-noconflict/ace.js"></script>	
				<!-- <script type="text/javascript" src="<?= WEBROOT ?>assets/bower_components/ace-builds/src-noconflict/ext-language_tools.js"></script>	-->
				<!--- <script type="text/javascript" src="<?= WEBROOT ?>assets/bower_components/dropzone/downloads/dropzone.js"></script> -->				
			<?php endif; ?>
		<?php else: ?>
			<script src="<?= WEBROOT ?>assets/bower_components/jquery/jquery.js"></script>
			<script src="<?= WEBROOT ?>assets/bower_components/webshim/js-webshim/dev/polyfiller.js"></script>
			<script src="<?= WEBROOT ?>assets/bower_components/bootstrap/dist/js/bootstrap.js"></script>
			<script src="<?= WEBROOT ?>assets/bower_components/fastclick/lib/fastclick.js"></script> 
			<script type="text/javascript" src="<?= WEBROOT ?>assets/bower_components/select2/select2.js"></script>
			
			<?php if ($site->inAdminArea()): ?>
				<script type="text/javascript" src="<?= WEBROOT ?>assets/bower_components/ace-builds/src-noconflict/ace.js"></script>	
				<!--- script type="text/javascript" src="<?= WEBROOT ?>assets/bower_components/ace-builds/src-noconflict/ext-language_tools.js"></script>	-->
				<!--- <script type="text/javascript" src="<?= WEBROOT ?>assets/bower_components/dropzone/downloads/dropzone.js"></script> -->	
			<?php endif; ?>
		<?php endif; ?>

		<script src="<?= WEBROOT ?>assets/main.js"></script>
		<script src="<?= WEBROOT ?>assets/highlight/highlight.pack.js"></script>
		<?php echo isset($js) ? $js : '' ?>
	</head>
	<body>
		<div class="container">

			<!--[if lt IE 7]>
				<p class="alert chromeframe">
			 <button type="button" class="close" data-dismiss="alert">&times;</button>
			   You are using an <strong>outdated</strong> browser. Please <a href="http://browsehappy.com/">upgrade your browser</a> or <a href="http://www.google.com/chromeframe/?redirect=true">activate Google Chrome Frame</a> to improve your experience.</p>
			<![endif]-->
