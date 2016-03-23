<?php header('Content-type: text/html; charset=utf-8');
?><!DOCTYPE html>

<html>
	<head> 
        <title><?php echo $site->makeTitle(); ?></title>
        <meta charset="utf-8"> 
		<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

		<script type="text/javascript" src="<?= asset_url('assets/'. (DEBUG ? 'lib' : 'minified') . '/webshim.js') ; ?>"></script>	
		<link rel="stylesheet" type="text/css" href="<?= asset_url('assets/lib/bower'. (DEBUG ? '' : '.min') . '.css') ; ?>" />

		<?php echo isset($css) ? $css : '' ?>

	</head>
	<body>
		<div class="container">

			<!--[if lt IE 7]>
				<p class="alert chromeframe">
			 <button type="button" class="close" data-dismiss="alert">&times;</button>
			   You are using an <strong>outdated</strong> browser. Please <a href="http://browsehappy.com/">upgrade your browser</a> or <a href="http://www.google.com/chromeframe/?redirect=true">activate Google Chrome Frame</a> to improve your experience.</p>
			<![endif]-->
