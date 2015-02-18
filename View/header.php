<?php header('Content-type: text/html; charset=utf-8');
?><!DOCTYPE html>

<html>
	<head> 
        <title><?php echo $site->makeTitle(); ?></title>
        <meta charset="utf-8"> 
		<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

		<link rel="stylesheet" type="text/css" href="<?= WEBROOT ?>assets/lib/bower<?=DEBUG?"":".min"?>.css" />
		
		<?php echo isset($css) ? $css : '' ?>
		
		<script type="text/javascript" src="<?= WEBROOT ?>assets/<?=DEBUG?"lib":"minified"?>/bower.js"></script>	
		<?php echo isset($js) ? $js : '' ?>
		<script type="text/javascript">

		  var _gaq = _gaq || [];
		  _gaq.push(['_setAccount', 'UA-45924096-1']);
		  _gaq.push(['_trackPageview']);

		  (function() {
		    var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
		    ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
		    var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);
		  })();

		</script>
	</head>
	<body>
		<div class="container">

			<!--[if lt IE 7]>
				<p class="alert chromeframe">
			 <button type="button" class="close" data-dismiss="alert">&times;</button>
			   You are using an <strong>outdated</strong> browser. Please <a href="http://browsehappy.com/">upgrade your browser</a> or <a href="http://www.google.com/chromeframe/?redirect=true">activate Google Chrome Frame</a> to improve your experience.</p>
			<![endif]-->
