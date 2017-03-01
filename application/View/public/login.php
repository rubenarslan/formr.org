
<?php Template::load('header'); ?>

<section id="fmr-header">
	<div class="container">
		<?php Template::load('public_nav'); ?>
	</div>
</section>

<section id="fmr-hero" class="js-fullheight full" data-next="yes">
	<div class="fmr-overlay"></div>
	<div class="container">
		<div class="fmr-intro">
			<div class="row">
				<div class="col-md-6 col-md-push-6">
					<div class="fmr-intro-text">
						<div class="col-md-12">
							<div class="login-form mdl-card mdl-shadow--2dp pull-right" style="">
								<h2>formr login</h2>
								<?= Template::load('public/alerts') ?>
								<form class="" id="login" name="login" method="post" action="<?php echo site_url('login');?>">
									<div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
										<input class="mdl-textfield__input" type="email" id="email" name="email">
										<label class="mdl-textfield__label" for="email"><i class="fa fa-envelope-o fa-fw"></i> Email</label>
									</div>
									<div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
										<input class="mdl-textfield__input" type="password" id="pass" name="password">
										<label class="mdl-textfield__label" for="pass"><i class="fa fa-key fa-fw"></i> Password</label>
									</div>
									<button type="submit" class="btn-primary mdl-button mdl-js-button mdl-button--raised mdl-js-ripple-effect">Sign In</button>
									<p>&nbsp;</p>
									<a href="<?php echo site_url('forgot_password'); ?>">I forgot my password.</a>
								</form>
							</div>
						</div>
					</div>
				</div>
				<div class="col-md-6 col-md-pull-6">
					<div class="fmr-intro-text">
						<div class="fmr-center-position">
							<h2 class="animate-box">Login</h2>
							<h3>Login to manage your existing studies or create new studies</h3>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
	<div class="clear"></div>
</section>


<?php Template::load('footer'); ?>

