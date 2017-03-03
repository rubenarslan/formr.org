<?php header('Content-type: text/html; charset=utf-8'); ?><!DOCTYPE html>
<html class="no-js">
    <head>
		<script>(function (H) {
                H.className = H.className.replace(/\bno_js\b/, 'js')
            })(document.documentElement)</script>
		<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <title><?php echo $site->makeTitle(); ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
		<meta charset="utf-8">
        <meta name="description" content="formr - an online survey framework with live feedback" />
        <meta name="keywords" content="formr, online survey, R software, opencpu, live feedback" />
        <meta name="author" content="formr.org" />

        <meta property="og:title" content="<?php echo $site->makeTitle(); ?>"/>
        <meta property="og:image" content=""/>
        <meta property="og:url" content="https://formr.org"/>
        <meta property="og:site_name" content="formr"/>
        <meta property="og:description" content="an online survey framework with live feedback"/>
        <meta name="twitter:title" content="formr" />
        <meta name="twitter:image" content="" />
        <meta name="twitter:url" content="https://formr.org" />
        <meta name="twitter:card" content="an online survey framework with live feedback" />

		<?php
		foreach ($css as $file) {
			echo '<link href="' . asset_url($file) . '" rel="stylesheet" type="text/css">' . "\n";
		}
		?>
		<?php
		foreach ($js as $file) {
			echo '<script src="' . asset_url($file) . '"></script>' . "\n";
		}
		?>

    </head>

	<body>

		<div id="fmr-page" class="<?php echo !empty($bodyClass) ? $bodyClass : 'body'; ?>">
			
			<section id="fmr-header" class="<?php echo !empty($headerClass) ? $headerClass : 'header'; ?>">
				<div class="container">
					<?php Template::load('public/navigation'); ?>
				</div>
			</section>

			

