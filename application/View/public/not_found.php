<?php 
	Template::load('public/header'); 
?>

<section id="fmr-hero" class="js-fullheight full">
	<div class="fmr-overlay"></div>
	<div class="container">
		<div class="fmr-intro js-fullheight">
			<div class="row">
				<div class="broken_tape">
					<div class="fmr-intro-text">
						<div class="col-md-12">
							<div class="login-form mdl-card mdl-shadow--2dp">
								<h2>Oops!</h2>
								<?php
								$alerts = $site->renderAlerts();
								if ($alerts) {
									echo $alerts;
								} else {
									echo '<h4 class="lead">The resource you are looking for have either been moved or does not exist</h4>';
								}
								?>
								<a href="<?= site_url() ?>"><i class="fa fa-home"></i> Go to home</a>
							</div>
						</div>
					</div>
				</div>
				<div class="clearfix"></div>

			</div>
		</div>
	</div>
	<div class="clear"></div>
</section>

<?php Template::load('footer'); ?>
