<?php Template::load('header_nav'); ?>
<div class="row">
	<div class="col-lg-4 col-lg-offset-1 col-sm-5 col-sm-offset-1 col-xs-12 well">
		<h2>Sign up</h2>
		<p class="lead">It's free. We don't spam.</p>
		<form class="" id="register" name="register" method="post" action="<?=WEBROOT?>public/register">
			<div class="form-group small-left">
				<label class="control-label sr-only" for="email">
					<?php echo _("Email"); ?>
				</label>
				<div class="controls">
					<div class="input-group">
					  <span class="input-group-addon"><i class="fa fa-envelope-o fa-fw"></i></span>
					  <input class="form-control"  required type="email" placeholder="Your email address" name="email" id="email">
					</div>
				</div>
			</div>
			<div class="form-group small-left">
				<label class="control-label sr-only" for="password">
					<?php echo _("Password"); ?>
				</label>
				<div class="controls">
					<div class="input-group">
					  <span class="input-group-addon"><i class="fa fa-key fa-fw"></i></span>
			  		  <input class="form-control" required type="password" placeholder="Please choose a secure phrase" name="password" id="password">
					</div>
				</div>
			</div>
			<div class="form-group small-left">
				<label class="control-label sr-only" for="referrer_code">
					Referral token (optional)
				</label>
				<div class="controls">
					<div class="input-group">
					  <span class="input-group-addon"><i class="fa fa-gift fa-fw"></i></span>
			  		  <input class="form-control" type="text" placeholder="Enter your referral token if you have one" name="referrer_code" id="referrer_code">
					</div>
				</div>
			</div>
			<p>If you don't have a referral token, you will need to write us an <a title=" We're excited to have people try this out, so you'll get a test account, if you're human or at least cetacean. But let us know a little about what you plan to do." class="schmail" href="mailto:IMNOTSENDINGSPAMTOruben.arslan@that-big-googly-eyed-email-provider.com?subject=<?=rawurlencode("formr private beta");?>&amp;body=<?=rawurlencode("If you are not a robot, I have high hopes that you can figure out how to get my proper email address from the above.Hi there!

I'd like an admin account on formr. I'm totally not a robot.

I already have registered with the email address from which I'm sending this request. 

I'm affiliated with the University of Atlantis

This is what I want to use formr for:
[x] find out more about land mammals
[x] plan cetacean world domination 
[ ] excessively use your server resources
");?>">email</a> to ask for an admin account. If you have one, you'll get your admin account once you confirm your email address.</p>
			
			<div class="form-group small-left">
				<div class="controls">
					<button class="btn btn-default btn-success" type="submit"><i class="fa fa-pencil fa-fw"></i> <?php echo _("sign up"); ?></button>
				</div>
			</div>
			
		</form>
	</div>
</div>
<?php Template::load('footer');
