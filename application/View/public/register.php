<?php 
	Template::load('public/header'); 
?>

<section id="fmr-hero" class="js-fullheight full" data-next="yes">
	<div class="fmr-overlay"></div>
	<div class="container">
		<div class="fmr-intro js-fullheight">
			<div class="row">

				<div class="col-md-6 col-md-push-6">
					<div class="fmr-intro-text">
						<div class="col-md-12">
							<div class="login-form well pull-right" style="">
								<h2>formr sign-up</h2>
								<?php Template::load('public/alerts'); ?>
								
								<form class="" id="register" name="register" method="post" action="<?php echo site_url('register'); ?>">
									<div class="form-group label-floating">
										<label class="control-label" for="email"><i class="fa fa-envelope"></i> Email</label>
										<input class="form-control" type="email" id="email" name="email" required>
									</div>
									<div class="form-group label-floating">
										<label class="control-label" for="pass"><i class="fa fa-lock"></i> Password</label>
										<input class="form-control" type="password" id="pass" name="password" required>
									</div>
									<div class="form-group label-floating">
										<label class="control-label" for="token"><i class="fa fa-gift"></i> Referral token (if available)</label>
										<input class="form-control" type="text" id="token" name="referrer_code">
									</div>
									
									<button type="submit" class="btn btn-sup btn-material-pink btn-raised"><i class="fa fa-pencil fa-fw"></i> Sign Up</button>
								</form>
							</div>
						</div>
						<div class="clearfix"></div>
					</div>
					<p>&nbsp;</p>
				</div>
				<div class="col-md-6 col-md-pull-6">
					<div class="fmr-intro-text">
						<div class="fmr-center-position">
							<h2 class="animate-box">Sign-Up</h2>
							<h3>It's free, we don't spam</h3>
							<p>If you don't have a referral token, you will need to write us an <a title=" We're excited to have people try this out, so you'll get a test account, if you're human or at least cetacean. But let us know a little about what you plan to do." class="schmail" href="mailto:IMNOTSENDINGSPAMTOruben.arslan@that-big-googly-eyed-email-provider.com?subject=<?=rawurlencode("formr private beta");?>&amp;body=<?=rawurlencode("If you are not a robot, I have high hopes that you can figure out how to get my proper email address from the above.Hi there!

I'd like an admin account on formr. I'm totally not a robot.

I already have registered with the email address from which I'm sending this request. 

I'm affiliated with the University of Atlantis

This is what I want to use formr for:
[x] find out more about land mammals
[x] plan cetacean world domination 
[ ] excessively use your server resources
");?>">email</a> to ask for an admin account. If you have one, you'll get your admin account once you confirm your email address.</p>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
	<div class="clear"></div>
</section>

<?php Template::load('public/footer'); ?>
