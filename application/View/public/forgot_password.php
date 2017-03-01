<?php Template::load('header'); ?>

<section id="fmr-header">
	<div class="container">
		<?php Template::load('public_nav'); ?>
	</div>
</section>

<section id="fmr-hero" class="js-fullheight full" data-next="yes">
	<div class="fmr-overlay"></div>
	<div class="container">
		<div class="fmr-intro js-fullheight">
			<div class="row">
				<div class="col-md-6 col-md-offset-3">
					<div class="fmr-intro-text">
						<div class="col-md-12">
							<div class="login-form below-header mdl-card mdl-shadow--2dp">
								<h2>Forgot Password</h2>
								<?= Template::load('public/alerts') ?>
								<h4 class="lead">Enter your email below and a link to reset your password will be sent to you.</h4>
								<form class="" id="login" name="login" method="post" action="<?php echo site_url('forgot_password'); ?>">
									<div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
										<input class="mdl-textfield__input" type="text" id="email" name="email" required>
										<label class="mdl-textfield__label" for="email"><i class="fa fa-envelope-o fa-fw"></i> Email</label>
									</div>
									<button class="btn-primary mdl-button mdl-js-button mdl-button--raised mdl-js-ripple-effect"><i class="fa fa-send"></i> Send Link</button>
								</form>
							</div>
						</div>
					</div>
				</div>
				<div class="clearfix"></div>
				<p>&nbsp;</p>
			</div>
		</div>
	</div>
	<div class="clear"></div>
</section>


<?php Template::load('footer'); ?>


