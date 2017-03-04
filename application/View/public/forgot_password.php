<?php 
	Template::load('public/header'); 
?>

<section id="fmr-hero" class="js-fullheight full" data-next="yes">
	<div class="fmr-overlay"></div>
	<div class="container">
		<div class="fmr-intro js-fullheight">
			<div class="col-md-12">
				<div class="col-md-6 col-md-offset-3">
					<div class="fmr-intro-text">
						<div class="col-md-12">
							<div class="login-form large well">
								<h2>Forgot Password</h2>
								<?= Template::load('public/alerts') ?>
								<h4 class="lead">Enter your email below and a link to reset your password will be sent to you.</h4>
								<form class="" id="login" name="login" method="post" action="<?php echo site_url('forgot_password'); ?>">
									<div class="form-group label-floating">
										<label class="control-label" for="email"><i class="fa fa-envelope" required></i> Email</label>
										<input class="form-control" type="email" id="email" name="email">
									</div>
									<button class="btn btn-sup btn-material-pink btn-raised"><i class="fa fa-send"></i> Send Link</button>
								</form>
							</div>
						</div>
						<div class="clearfix"></div>
					</div>
				</div>
				<div class="clearfix"></div>
				<p>&nbsp;</p>
			</div>
		</div>
	</div>
	<div class="clear"></div>
</section>


<?php Template::load('public/footer'); ?>


