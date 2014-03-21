<div class="lead">
	<h3>formr features</h3>
<p>There are three main components: surveys, runs and the accompanying R package.</p>
<h4>Surveys</h4>
<p>
	are simple or complicated forms and surveys used to gather information in a single session.
</p>
<p>
	There is a wide variety of items to choose from: text and number inputs, Likert scales, sliders, geolocation, date pickers, dropdowns and <a href="<?=WEBROOT?>public/documentation/#available_items">many more</a>. They are geared towards power users, so instead of dragging and dropping elements till your fingers bleed, you upload item spreadsheets that can easily be re-used, combined and shared.
</p>
	<h4>Runs</h4>
<p>
enable you to link surveys and chain them together. Using a number of boombox-themed control elements to control the participant's way through your study, you can design studies of <abbr title="All of these boombox-controls know R, so though you don't have to be an R-wizard to run a study with formr, it certainly helps with the limitless complexity aspect.">limitless</abbr> complexity. You can
<ul>
	<li>manage access to and eligibility for a study:
		<span class="">
			<i class="fa-fw fa fa-pencil-square"></i>
			<i class="fa-fw fa fa-forward"></i>
			<i class="fa-fw fa fa-stop"></i>
		</span>
	</li>
	<li>use different pathways for different users using branches:
		<span class="">
			<i class="fa-fw fa fa-pencil-square"></i>
			<i class="fa-fw fa fa-forward"></i>
			<i class="fa-fw fa fa-pencil-square"></i>
			<i class="fa-fw fa fa-forward"></i>
		</span>
			
	</li>
	<li>send email invites and reminders:
		<span class="">
			<i class="fa-fw fa fa-forward"></i>
			<i class="fa-fw fa fa-envelope"></i>
		</span>
		
	</li>
	<li>implement delays/pauses:
		<span class="">
			<i class="fa-fw fa fa-pause"></i>
		</span>
		
	</li>
	<li>add external modules:
		<span class="">
			<i class="fa-fw fa fa-external-link-square"></i>
		</span>
	</li>
	<li>loop surveys and thus enable diaries and experience-sampling studies:
		<span class="">
			<i class="fa-fw fa fa-envelope"></i>
			<i class="fa-fw fa fa-pencil-square"></i>
			<i class="fa-fw fa fa-backward"></i>
		</span>
		
	</li>
	<li>give custom feedback, through <a href="https://public.opencpu.org/pages">OpenCPU</a>'s R API.
		<span class="">
			<i class="fa-fw fa fa-stop"></i>
		</span>
	</li>
	<li>randomise participants into groups for e.g. A-B-testing or experiments<br>
		<span class="">
			<i class="fa-fw fa fa-random"></i>
			<i class="fa-fw fa fa-forward"></i>
			<i class="fa-fw fa fa-pencil-square"></i>
		</span>
	</li>
</ul>
</p>
<p>
	The following designs and many more are possible:
	<ul>
		<li>simple one-shot surveys
		</li>
		<li>complex one-shot surveys (using skipping logic, personalised text, complex feedback
		</li>
		<li>surveys with eligibility limitations
		</li>
		<li>diary studies including completely flexible automated email reminders</li>
		<li>longitudinal studies (ie. wait 2 months after last participation or re-contact after they return from their exchange year). The items of later waves need not exist in final form at wave 1.</li>
		<li>longitudinal social networks and other studies that require rating a variable number of things or persons</li>
	</ul>
</p>
<h4>R package</h4>
<p>
	Wherever you use R in formr you can also use the functions in its R package. If you want to use the package in a different environment,
	you'll need to install it using these two lines of code.	
</p>
<pre><code class="r">install.packages("devtools")
devtools::install_github("rubenarslan/formr/formr-package")</code></pre>
<p>The package currently has the following feature sets</p>
<ul>
	<li>Connecting to formr, importing your data, correctly typing all variables, automatically aggregating scales.</li>
	<li>Easily making feedback plots e.g. <pre><code class="r">qplot_on_normal(0.8, "Extraversion")</code></pre>
		The package also has a function to simulate possible data, so you can make feedback plots ahead of collecting data.</li>
	<li>Some shorthand functions for frequently needed operations on the site: <pre><code class="r">first(cars); last(cars); current(cars); "formr." %contains% "mr."</code></pre>.</li>
</ul>
</div>