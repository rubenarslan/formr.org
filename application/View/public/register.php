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

				<div class="col-md-6 col-md-push-6">
					<div class="fmr-intro-text">
						<div class="col-md-12">
							<div class="login-form below-header mdl-card mdl-shadow--2dp pull-right" style="">
								<h2>formr sign-up</h2>
								<?php Template::load('public/alerts'); ?>
								
								<form class="" id="register" name="register" method="post" action="<?php echo site_url('register'); ?>">
									<div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
										<input class="mdl-textfield__input" type="email" id="email" name="email">
										<label class="mdl-textfield__label" for="email"><i class="fa fa-envelope-o fa-fw"></i> Email</label>
									</div>
									<div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
										<input class="mdl-textfield__input" type="text" id="pass" name="password">
										<label class="mdl-textfield__label" for="pass"><i class="fa fa-key fa-fw"></i> Password</label>
									</div>
									<div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
										<input class="mdl-textfield__input" type="text" id="token" name="referrer_code">
										<label class="mdl-textfield__label" for="token"><i class="fa fa-gift fa-fw"></i> Referral token (if available)</label>
									</div>
									<button type="submit" class="btn-primary mdl-button mdl-js-button mdl-js-ripple-effect"><i class="fa fa-pencil fa-fw"></i> Sign Up</button>
								</form>
							</div>
						</div>
					</div>
				</div>
				<div class="col-md-6 col-md-pull-6">
					<div class="below-header fmr-intro-text">
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

<?php Template::load('footer'); ?>
