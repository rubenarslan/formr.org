<?php header('Content-type: text/html; charset=utf-8'); ?><!DOCTYPE html>
<html class="no_js">
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

        <div id="fmr-page" class="fmr-about">
            <div class="container run-container">
                <div class="row">
                    <div class="col-lg-12 run_position_<?php echo $run_session->position; ?> run_unit_type_<?php echo $run_session->current_unit_type; ?> run_content">	
                        <header class="run_content_header">
							<?php if ($run->header_image_path): ?>
								<img src="<?php echo $run->header_image_path; ?>" alt="<?php echo $run->name; ?> header image">
							<?php endif; ?>
                        </header>

						<div class="row">
							<?php Template::load('public/alerts'); ?>
						</div>

						<?php echo $run_content; ?>
                    </div>
                </div>
            </div>
        </div>

    </body>
</html>