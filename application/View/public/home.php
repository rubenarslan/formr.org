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
					<h3>chain simple forms & surveys into long runs, use the power of R to generate pretty feedback and complex designs</h3>
					<p><a href="<?= site_url('register'); ?>" class="btn btn-primary btn-lg">Sign up &amp; Get started</a></p>
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

<section id="fmr-projects">
	<div class="container">
		<div class="row row-bottom-padded-md">
			<div class="col-md-6 col-md-offset-3 text-center">
				<a name="home-more"></a>
				<h2 class="fmr-lead animate-box">What formr can do</h2>
				<p class="fmr-sub-lead animate-box">In addition to much more, you can achieve the following while using formr to design your online studies. </p>
			</div>
		</div>
		<div class="row">
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
					<img src="<?= asset_url('build/img/feedback.jpg') ?>" alt="Image" class="img-responsive">
					<div class="fmr-text">
						<h2>Survey Feedback</h2>
						<p class="text-left">simple surveys with and without feedback. Uses R to generate feedack</p>
					</div>
				</div>
			</div>

			<div class="col-md-4 col-sm-6 col-xxs-12 animate-box">
				<div class="fmr-project-item">
					<img src="<?= asset_url('build/img/yes-no.jpg') ?>" alt="Image" class="img-responsive">
					<div class="fmr-text">
						<h2>Eligibility Limitations</h2>
						<p class="text-left">Surveys with eligibility limitations. Filter participants with eligibility criteria.</p>
					</div>
				</div>
			</div>
			<div class="col-md-4 col-sm-6 col-xxs-12 animate-box">
				<div class="fmr-project-item">
					<img src="<?= asset_url('build/img/diary.jpg') ?>" alt="Image" class="img-responsive">
					<div class="fmr-text">
						<h2>Diary Studies</h2>
						<p  class="text-left">diary studies including completely flexible automated email/text message reminders</p>
					</div>
				</div>
			</div>
			<div class="col-md-4 col-sm-6 col-xxs-12 animate-box">
				<div class="fmr-project-item">
					<img src="<?= asset_url('build/img/maze.jpg') ?>" alt="Image" class="img-responsive">
					<div class="fmr-text">
						<h2>Longitudinal Studies</h2>
						<p class="text-left">longitudinal studies. The items of later waves need not exist in final form at wave 1.</p>
					</div>
				</div>
			</div>
			<div class="col-md-4 col-sm-6 col-xxs-12 animate-box">
				<div class="fmr-project-item">
					<img src="<?= asset_url('build/img/people-social-network.jpg') ?>" alt="Image" class="img-responsive">
					<div class="fmr-text">
						<h2>Longitudinal Social Networks</h2>
						<p class="text-left">Networks and other studies that require rating a variable number of persons</p>
					</div>
				</div>
			</div>

		</div>
	</div>
</section>
<!-- END #fmr-projects -->

<section id="fmr-features">
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
					<p>generates live feedback, including <a href="http://ggplot2.org/">ggplot2</a>, interactive <a href="http://ggvis.rstudio.com">ggvis</a> plots and <a href="http://www.htmlwidgets.org">htmlwidgets</a>. This increases interest and retention in our studies.</p>
				</div>
			</div>
			<div class="col-md-4 col-sm-6 col-xs-12 animate-box">
				<div class="fmr-feature">
					<div class="fmr-icon">
						<i class="fa fa-book"></i>
					</div>
					<h3>Complex Experience</h3>
					<p>automates complex experience sampling, diary and training studies, including automated reminders via email or text message</p>
				</div>
			</div>
			<div class="clearfix visible-sm-block"></div>
			<div class="col-md-4 col-sm-6 col-xs-12 animate-box">
				<div class="fmr-feature">
					<div class="fmr-icon">
						<i class="fa fa-desktop"></i>
					</div>
					<h3>Responsive Layout</h3>
					<p>Various device sizes are supported  whilst displaying surveys (about 30-40% of participants fill out our surveys on a mobile device)</p>
				</div>
			</div>

			<div class="col-md-4 col-sm-6 col-xs-12 animate-box">
				<div class="fmr-feature">
					<div class="fmr-icon">
						<i class="fa fa-file"></i>
					</div>
					<h3>Easy to Use</h3>
					<p>easily share, swap and combine surveys (they're simply spreadsheets) and runs (you can share complete designs, e.g. "daily diary study")</p>
				</div>
			</div>
			<div class="clearfix visible-sm-block"></div>
			<div class="col-md-4 col-sm-6 col-xs-12 animate-box">
				<div class="fmr-feature">
					<div class="fmr-icon">
						<i class="fa fa-cog"></i>
					</div>
					<h3>Use R</h3>
					<p>you can use R to do basically anything that R can do (i.e. complicated stuff, like using a sentiment analysis of a participant's Twitter feed to decide when the survey happens)</p>
				</div>
			</div>
			<div class="col-md-4 col-sm-6 col-xs-12 animate-box">
				<div class="fmr-feature">
					<div class="fmr-icon">
						<i class="fa fa-link"></i>
					</div>
					<h3>Cross Platform</h3>
					<p>not jealous at all – feel free to integrate other components (other survey engines, reaction time tasks, whatever you are used to) with formr, we tried our best to make it easy</p>
				</div>
			</div>
			<div class="clearfix visible-sm-block"></div>
		</div>
	</div>
</section>	
<!-- END #fmr-features -->

<section id="fmr-features-2">
	<div class="container">
		<div class="col-md-6 col-md-push-6">
			<figure class="fmr-feature-image animate-box">
				<img src="<?= asset_url('build/img/macbook.png')?>" alt="">
			</figure>
		</div>
		<div class="col-md-6 col-md-pull-6">
			<h2 class="fmr-lead animate-box">Superb Features</h2>
			<div class="fmr-feature">
				<div class="fmr-icon animate-box"><i class="fa fa-check-circle fa-4x"></i></div>
				<div class="fmr-text animate-box">
					<h3>Simple and Complex online studies</h3>
					<p>Far far away, behind the word mountains, far from the countries Vokalia and Consonantia, there live the blind texts. </p>
				</div>
			</div>
			<div class="fmr-feature">
				<div class="fmr-icon animate-box"><i class="fa fa-check-circle fa-4x"></i></div>
				<div class="fmr-text animate-box">
					<h3>Instant Feedback</h3>
					<p>Far far away, behind the word mountains, far from the countries Vokalia and Consonantia, there live the blind texts. </p>
				</div>
			</div>
			<div class="fmr-feature">
				<div class="fmr-icon animate-box"><i class="fa fa-check-circle fa-4x"></i></div>
				<div class="fmr-text animate-box">
					<h3>Text &amp; Email Messaging</h3>
					<p>Far far away, behind the word mountains, far from the countries Vokalia and Consonantia, there live the blind texts. </p>
				</div>
			</div>

			<div class="fmr-btn-action animate-box">
				<a href="<?= site_url('documentation/#features'); ?>" class="btn btn-primary btn-cta">More Features</a>
			</div>

		</div>
	</div>
</section>
<!-- END #fmr-features-2 -->

<?php Template::load('public/disclaimer'); ?>

<?php Template::load('public/newsletter'); ?>

<?php Template::load('footer'); ?>
			
