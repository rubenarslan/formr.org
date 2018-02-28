<?php Template::load('public/header'); ?>

<!-- #fmr-header -->

<section id="fmr-hero" class="js-fullheight" data-next="yes">
	<div class="fmr-overlay"></div>
	<div class="container">
		<div class="fmr-intro js-fullheight">
			<div class="fmr-intro-text">
				<div class="fmr-center-position">
					<h2 class="animate-box"><b>formr</b> survey framework</h2>
					<?php Template::load('public/alerts'); ?>
					<h3>chain simple surveys into long runs, use the power of R to generate pretty feedback and complex designs</h3>
					<p><a href="<?= site_url('register'); ?>" class="btn btn-primary btn-lg btn-raised btn-material-pink">Sign up (it's all free)</a></p>
				</div>
			</div>
		</div>
	</div>
	<div class="fmr-learn-more animate-box">
		<a href="#home-more" class="scroll-buttonn">
			<span class="arrow"><i class="fa fa-chevron-down"></i></span>
		</a>
	</div>
</section>
<!-- END #fmr-hero -->

<section id="fmr-features">
	<a name="home-more"></a>
	<div class="container">
		<div class="row text-center row-bottom-padded-md">
			<div class="col-md-8 col-md-offset-2">
				<h2 class="fmr-lead animate-box">Core Strengths</h2>
				<p class="fmr-sub-lead animate-box">The core strengths of formr</p>
			</div>
		</div>
		<div class="row">
			<div class="col-md-4 col-sm-6 col-xs-12 animate-box">
				<div class="fmr-feature">
					<div class="fmr-icon">
						<i class="fa fa-line-chart"></i>
					</div>
					<h3>Live Feedback</h3>
					<p>generates live and interactive feedback, including <a href="http://ggplot2.org/">ggplot2</a>, interactive <a href="http://ggvis.rstudio.com">ggvis</a> and <a href="http://www.htmlwidgets.org">htmlwidgets</a>. In our studies, this increases interest and retention. <a href="<?php echo site_url('interactive_charts'); ?>">See examples.</a></p>
				</div>
			</div>
			<div class="col-md-4 col-sm-6 col-xs-12 animate-box">
				<div class="fmr-feature">
					<div class="fmr-icon">
						<i class="fa fa-envelope"></i>
					</div>
					<h3>Reminders, invitations</h3>
					<p>sends automated reminders via email or text message, you can generate custom text and feedback here too</p>
				</div>
			</div>
			<div class="col-md-4 col-sm-6 col-xs-12 animate-box">
				<div class="fmr-feature">
					<div class="fmr-icon">
						<i class="fa fa-mobile"></i>
					</div>
					<h3>Responsive Layout</h3>
					<p>all platforms and device sizes are supported (about 30-40% of participants fill out our surveys on a mobile device)</p>
				</div>
			</div>
			<div class="clear clearfix"></div>

			<div class="col-md-4 col-sm-6 col-xs-12 animate-box">
				<div class="fmr-feature">
					<div class="fmr-icon">
						<i class="fa fa-file"></i>
					</div>
					<h3>Share &amp; Remix</h3>
					<p>easily share, swap and remix surveys (they're just spreadsheets) and runs (they're just JSON). Track version changes in these files with e.g. the <a href="https://osf.io">OSF</a>.</p>
				</div>
			</div>
			<div class="clearfix visible-sm-block"></div>
			<div class="col-md-4 col-sm-6 col-xs-12 animate-box">
				<div class="fmr-feature">
					<div class="fmr-icon">
						<i class="fa fa-code"></i>
					</div>
					<h3>Use R</h3>
					<p>use R to do anything it can do (plot a graph or even use a sentiment analysis of a participant's Twitter feed to decide which questions to ask)</p>
				</div>
			</div>
			<div class="col-md-4 col-sm-6 col-xs-12 animate-box">
				<div class="fmr-feature">
					<div class="fmr-icon">
						<i class="fa fa-lock"></i>
					</div>
					<h3>Secure and open source</h3>
					<p>Participants can only connect via an SSL-encrypted connection. <a href="<?php echo site_url('about#security'); ?>">Learn more about security on formr</a>.</p>
				</div>
			</div>
			<div class="clearfix visible-sm-block"></div>
		</div>
	</div>
</section>	
<!-- END #fmr-features -->

<section id="fmr-projects">
	<div class="container">
		<div class="row row-bottom-padded-md">
			<div class="col-md-6 col-md-offset-3 text-center">
				<h2 class="fmr-lead animate-box">What formr can do</h2>
				<p class="fmr-sub-lead animate-box">These study types (and others) can be implemented in formr.org</p>
			</div>
		</div>
		<div class="row">
			<div class="col-md-4 col-sm-6 col-xxs-12 animate-box">
				<div class="fmr-project-item">
					<img src="<?= asset_url('build/img/feedback.png') ?>" alt="Image" class="img-responsive">
					<div class="fmr-text">
						<h2>Surveys with feedback</h2>
						<p class="text-left">generate simple surveys via spreadsheets. With and without feedback. Use R to generate feedback</p>
					</div>
				</div>
			</div>
			<div class="col-md-4 col-sm-6 col-xxs-12 animate-box">
				<div class="fmr-project-item">
					<img src="<?= asset_url('build/img/survey.jpg') ?>" alt="Image" class="img-responsive">
					<div class="fmr-text">
						<h2>Complex Surveys</h2>
						<p class="text-left">complex surveys (using skipping logic, personalised text, complex feedback)</p>
					</div>
				</div>
			</div>


			<div class="col-md-4 col-sm-6 col-xxs-12 animate-box">
				<div class="fmr-project-item">
					<img src="<?= asset_url('build/img/yes-no.jpg') ?>" alt="Image" class="img-responsive">
					<div class="fmr-text">
						<h2>Eligibility Limitations</h2>
						<p class="text-left">filter your participants with eligibility criteria.</p>
					</div>
				</div>
			</div>
			<div class="col-md-4 col-sm-6 col-xxs-12 animate-box">
				<div class="fmr-project-item">
					<img src="<?= asset_url('build/img/diary.png') ?>" alt="Image" class="img-responsive">
					<div class="fmr-text">
						<h2>Diary Studies</h2>
						<p  class="text-left">do diary studies with flexible automated email/text message reminders</p>
					</div>
				</div>
			</div>
			<div class="col-md-4 col-sm-6 col-xxs-12 animate-box">
				<div class="fmr-project-item">
					<img src="<?= asset_url('build/img/longitudinal_study.png') ?>" alt="Image" class="img-responsive">
					<div class="fmr-text">
						<h2>Longitudinal Studies</h2>
						<p class="text-left">do longitudinal studies. The items of later waves need not exist in final form at wave 1.</p>
					</div>
				</div>
			</div>
			<div class="col-md-4 col-sm-6 col-xxs-12 animate-box">
				<div class="fmr-project-item">
					<img src="<?= asset_url('build/img/people-social-network.jpg') ?>" alt="Image" class="img-responsive">
					<div class="fmr-text">
						<h2>Longitudinal Social Networks</h2>
						<p class="text-left">let people rate their social networks and track changes in them</p>
					</div>
				</div>
			</div>

		</div>
	</div>
</section>
<!-- END #fmr-projects -->


<?php Template::load('public/disclaimer'); ?>

<?php Template::load('public/footer'); ?>
			
