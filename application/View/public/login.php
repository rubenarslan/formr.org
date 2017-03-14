<?php
Template::load('public/header');
?>

<section id="fmr-hero" class="js-fullheight full" data-next="yes">
	<div class="fmr-overlay"></div>
	<div class="container">
		<div class="fmr-intro">
			<div class="row">
				<div class="col-md-6 col-md-push-6">
					<div class="fmr-intro-text">
						<div class="col-md-12">
							<div class="login-form well pull-right" style="">
								<h2>formr login</h2>
								<?= Template::load('public/alerts') ?>
								<form class="" id="login" name="login" method="post" action="<?php echo site_url('login'); ?>">
									<div class="form-group label-floating">
										<label class="control-label" for="email"><i class="fa fa-envelope"></i> Email</label>
										<input class="form-control" type="email" id="email" name="email">
									</div>
									<div class="form-group label-floating">
										<label class="control-label" for="email"><i class="fa fa-lock"></i> Password</label>
										<input class="form-control" type="password" id="pass" name="password">
									</div>
									
									<button type="submit" class="btn btn-sup btn-material-pink btn-raised">Sign In</button>
									<p>&nbsp;</p>
									<a href="<?php echo site_url('forgot_password'); ?>">I forgot my password.</a>
								</form>
							</div>
						</div>
						<div class="clearfix"></div>
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


<?php Template::load('public/footer'); ?>

