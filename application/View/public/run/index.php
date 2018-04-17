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
			foreach ($css as $id => $files) {
				print_stylesheets($files, $id);
			}
			foreach ($js as $id => $files) {
				print_scripts($files, $id);
			}
		?>
		<link rel="icon" href="<?php echo site_url('favicon.ico'); ?>">
    </head>

	<body class="<?php echo isset($bodyClass) ? $bodyClass : ''; ?>">

        <div id="fmr-page" class="fmr-about">
            <div class="container run-container">
                <div class="row">
                    <div class="col-lg-12 run_position_<?php echo $run_session->position; ?> run_unit_type_<?php echo $run_session->current_unit_type; ?> run_content">	
                        <header class="run_content_header">
							<?php if ($run->header_image_path): ?>
								<img src="<?php echo $run->header_image_path; ?>" alt="<?php echo $run->name; ?> header image">
							<?php endif; ?>
                        </header>

						<div class="alerts-container">
							<?php Template::load('public/alerts'); ?>
						</div>

						<?php echo $run_content; ?>
                    </div>
                </div>
            </div>
        </div>

		<script id="tpl-feedback-modal" type="text/formr">
			<div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="FormR.org Modal" aria-hidden="true">
				<div class="modal-dialog">                         
					<div class="modal-content">                              
						<div class="modal-header">                                 
							<button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>                                 
							<h3>%{header}</h3>                             
						</div>                             
						<div class="modal-body">%{body}</div>
						<div class="modal-footer">                             
							<button class="btn" data-dismiss="modal" aria-hidden="true">Close</button>                         
						</div>                     
					</div>                 
				</div>
			</div>
		</script>
    </body>
</html>