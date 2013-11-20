<?php
require_once '../define_root.php';

require_once INCLUDE_ROOT."Model/Site.php";
require_once INCLUDE_ROOT."View/header.php";

require_once INCLUDE_ROOT."View/public_nav.php";

?>
<div class="row">
	<div class="col-lg-8 col-lg-offset-1 col-sm-9 col-sm-offset-1 col-xs-12">
		<div class="jumbotron">
			<h1>
				formr <small>in private beta</small>
			</h1>
			<p>
					chain simple forms &amp; surveys into long runs, use the power of R to generate pretty feedback and complex designs
			</p>
			<p>
				<a class="btn btn-primary btn-lg" role="button" class="<?=WEBROOT?>public/register">Register</a>
			</p>
		</div>
		<div class="well">
			<ul class="fa-ul lead">
				<li>
					<i class="fa fa-li fa-pencil-square"></i> You can <a href="<?=WEBROOT?>public/studies">take some of the published studies for a test run</a>.
				</li>
				<li>
					<i class="fa fa-li fa-file"></i> 
					You can read the <a href="<?=WEBROOT?>public/documentation" title="hopefully you'll get some idea of what formr can do for you">super exciting docs</a>.
				</li>
				<li>
					<i class="fa fa-li fa-rocket"></i> If you want to see formr's capabilities as an admin and make some studies yourself,
					<ul class="fa-ul">
						<li><i class="fa fa-li fa-envelope"></i> 
							<a title="Just send us an email. You'll get a test account, if you're human or even just a cat." href="mailto:ruben.arslan@that-big-googly-eyed-email-provider.com?subject=<?=rawurlencode("formr private beta");?>&amp;body=<?=rawurlencode("Hi!
	I'd like an admin account on formr. 
	I have registered with the email address I'm sending this request from already. 
	I'm affiliated with institution xxxx.
	I didn't forget to fix your email address, thus I'm not a spambot.");?>">request an admin account</a> or 
						</li>
						<li><i class="fa fa-li fa-github-alt"></i> 
							<a href="https://github.com/rubenarslan/formr" title="If you don't know what a Github repository is yet, this is probably not the option for you, but for your local techie type. ">check out the Github repo</a>.
						</li>
				</li>
			</ul>
		</div>
	</div>
	
</div>
<?php
require_once INCLUDE_ROOT."View/footer.php";
