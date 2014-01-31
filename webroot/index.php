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
				<span class="formr-brand">formr <small>survey framework</small></span>
			</h1>
			<p>
					chain simple forms &amp; surveys into long runs, use the power of <abbr title="A statistics environment. It makes nice plots!">R</abbr> to generate pretty feedback and complex designs
			</p>
			<p>
				<a class="btn btn-primary btn-lg" role="button" href="<?=WEBROOT?>public/register">Sign up (it's free!)</a>
			</p>
		</div>
		<div class="well">
			<ul class="fa-ul lead">
				<li>
					<i class="fa fa-li fa-pencil"></i> You can <a href="<?=WEBROOT?>public/register">register</a> for free to let us know you're interested.
				</li>
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
							you can <a title="Just send us an email. You'll get a test account, if you're human or feline or cetacean." class="schmail" href="mailto:IMNOTSENDINGSPAMTOruben.arslan@that-big-googly-eyed-email-provider.com?subject=<?=rawurlencode("formr private beta");?>&amp;body=<?=rawurlencode("If you are not a robot, I have high hopes that you can figure out how to get my proper email address from the above.

Hi!
I'd like an admin account on formr. 
I already have registered with the email address from which I'm sending this request. 
I'm affiliated with institution xxxx.
");?>">request an admin account</a> or 
						</li>
						<li><i class="fa fa-li fa-github-alt"></i> 
							you can <a href="https://github.com/rubenarslan/formr" title="If you don't know what a Github repository is yet, this is probably not the option for you, but for your local techie type. ">check out the Github repo</a>. It's open source and free-to-use.
						</li>
				</li>
			</ul>
		</div>
	</div>
	
</div>
<?php
require_once INCLUDE_ROOT."View/footer.php";
