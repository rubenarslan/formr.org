<h3>Features</h3><hr />

<p class="lead">
	The following designs and many more are possible:
</p>
<ul class="fa-ul-more-padding">
	<li>simple surveys with and without feedback
	</li>
	<li>complex surveys (using skipping logic, personalised text, complex feedback)
	</li>
	<li>surveys with eligibility limitations
	</li>
	<li>diary studies including completely flexible automated email/text message reminders</li>
	<li>longitudinal studies (e.g. automatically re-contact participants after they return from their exchange year). The items of later waves need not exist in final form at wave 1.</li>
	<li>longitudinal social networks and other studies that require rating a variable number of things or persons</li>
</ul>
<h2>
	Core strengths
</h2>
<ul class="fa-ul-more-padding">
	<li>
		generates very pretty feedback live, including <a href="http://ggplot2.org/">ggplot2</a>, and interactive <a href="http://ggvis.rstudio.com">ggvis</a> plots and <a href="http://www.htmlwidgets.org">htmlwidgets</a>. We find that this greatly increases interest and retention in our studies. 
	</li>
	<li>
		automates complex experience sampling, diary and training studies, including automated reminders via email or text message
	</li>
	<li>
		looks nice on a phone (about 30-40% of participants fill out our surveys on a mobile device)
	</li>
	<li>
		easily share, swap and combine surveys (they're simply spreadsheets) and runs (you can share complete designs, e.g. "daily diary study")
	</li>
	<li>
		you can use R to do basically anything that R can do (i.e. complicated stuff, like using a sentiment analysis of a participant's Twitter feed to decide when the survey happens)
	</li>
	<li>
		not jealous at all – feel free to integrate other components (other survey engines, reaction time tasks, whatever you are used to) with formr, we tried our best to make it easy.
	</li>
</ul>
<h2>
	Features
</h2>
<ul class="fa-ul-more-padding">
	<li>
		manage access to and eligibility for studies
	</li>
	<li>
		longitudinal studies
	</li>
	<li>
		send text messages (see the <a href="https://github.com/rubenarslan/formr.org/wiki/How-to-send-text-messages-(SMS)">HowTo</a>)
	</li>
	<li>
		works on all somewhat modern devices and degrades gracefully where it doesn't
	</li>
	<li>
		formats text using <a href="https://help.github.com/articles/github-flavored-markdown/">Github-flavoured</a>  <a href="https://help.github.com/articles/markdown-basics/">Markdown</a> (a.k.a. the easiest and least bothersome way to mark up text)
	</li>
	<li>
		file, image, video, sound uploads for users (as survey items) and admins (to supply study materials)
	</li>
	<li>
		complex conditional items
	</li>
	<li>
		a dedicated <a href="https://github.com/rubenarslan/formr/">formr R package</a>: makes pretty feedback graphs and complex run logic even simpler. Simplifies data wrangling (importing, aggregating, simulating data from surveys).
	</li>
	<li>
		a nice editor, <a href="https://github.com/ajaxorg/ace">Ace</a>, for editing Markdown &amp; R in runs.
	</li>
	
</ul>
<h3>
	Plans:
</h3>
<ul class="fa-ul-more-padding">
	<li>
		work offline on mobile phones and other devices with intermittent internet access (in the meantime <a href="https://enketo.org/">enketo</a> is pretty good and free too, but geared towards humanitarian aid)
	</li>

	<li>
		a better API (some basics are there)
	</li>
	<li>
		social networks, round robin studies - at the moment they can be implemented, but are a bit bothersome at first. There is a dedicated module already which might also get released as open source if there's time. 
	</li>
	<li>
		more <a href="https://github.com/rubenarslan/formr.org/issues?labels=enhancement&amp;page=1&amp;state=open">planned enhancements on Github</a>
	</li>
</ul>

<!--
<p>
enable you to link surveys and chain them together. Using a number of boombox-themed control elements to control the participant's way through your study, you can design studies of <abbr title="All of these boombox-controls know R, so though you don't have to be an R-wizard to run a study with formr, it certainly helps with the limitless complexity aspect.">limitless</abbr> complexity. You can
</p>

<ul>
	<li>manage access to and eligibility for a study:
		<span class="">
			<i class="fa-fw fa fa-pencil-square"></i>
			<i class="fa-fw fa fa-forward"></i>
			<i class="fa-fw fa fa-stop"></i>
		</span>
	</li>
	<li>use different pathways for different users:
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
-->
