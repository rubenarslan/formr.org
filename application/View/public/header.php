<?php header('Content-type: text/html; charset=utf-8'); ?><!DOCTYPE html>
<html class="no_js">
    <head>
		<script>(function (H) {
                H.className = H.className.replace(/\bno_js\b/, 'js')
            })(document.documentElement)</script>
        <title><?php echo $site->makeTitle(); ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="description" content="formr survey framework. chain simple surveys into long runs, use the power of R to generate pretty feedback and complex designs" />
        <meta name="keywords" content="formr, online survey, R software, opencpu, live feedback" />
        <meta name="author" content="formr.org" />

        <meta property="og:title" content="formr - an online survey framework with live feedback"/>
        <meta property="og:image" content="<?php echo asset_url('build/img/formr-og.png'); ?>"/>
		<meta property="og:image:url" content="<?php echo asset_url('build/img/formr-og.png'); ?>"/>
		<meta property="og:image:width" content="600" />
		<meta property="og:image:height" content="600" />
        <meta property="og:url" content="https://formr.org"/>
        <meta property="og:site_name" content="formr.org"/>
        <meta property="og:description" content="formr survey framework. chain simple surveys into long runs, use the power of R to generate pretty feedback and complex designs"/>
		
		<meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="formr - an online survey framework with live feedback" />
        <meta name="twitter:image" content="<?php echo asset_url('build/img/formr-og.png'); ?>" />
		<meta name="twitter:image:alt" content="formr.org logo" />
        <meta name="twitter:url" content="https://formr.org" />
        <meta name="twitter:description" content="formr survey framework. chain simple surveys into long runs, use the power of R to generate pretty feedback and complex designs" />

		<?php
			foreach ($css as $id => $files) {
				print_stylesheets($files, $id);
			}
			foreach ($js as $id => $files) {
				print_scripts($files, $id);
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

			

