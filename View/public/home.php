<?php Template::load('header_nav'); ?>

<div class="row">
	<div class="col-lg-8 col-lg-offset-1 col-sm-9 col-sm-offset-1 col-xs-12">
		<div class="jumbotron">
			<h1>
				<span class="formr-brand">formr <small>survey framework</small></span>
			</h1>
			<p>
					chain simple forms &amp; surveys into long runs, use the power of <abbr title="A statistics environment. Nice plots abound!">R</abbr> to generate pretty feedback and complex designs
			</p>
			<p>
				<a class="btn btn-primary btn-lg" role="button" href="<?=WEBROOT?>public/register">Sign up (it's free!)</a>
			</p>
		</div>
		<div class="">
			<ul class="nav nav-tabs">
			  <li class="active"><a href="#options" data-toggle="tab">What can I do here?</a></li>
			  <li><a href="#features" data-toggle="tab">What can formr do?</a></li>
			</ul>
			<div class="tab-content">
				<div class="well tab-pane fade in active" id="options">
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
							<i class="fa fa-li fa-rocket"></i> If you want to use formr to run your own studies,
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
								<li><i class="fa fa-li fa-google"></i> 
									you can <a href="https://groups.google.com/forum/#!forum/formr" title="you can ask and answer other admin users' questions here">join or browse our community help mailing list</a>.
								</li>
							</ul>
						</li>
					</ul>
				</div>
				<div class="well tab-pane fade" id="features">
					<?php Template::load('features'); ?>
				</div>
			</div>
			
		</div>
	</div>
	
</div>

<?php Template::load('footer'); ?>
