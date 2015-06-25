<?php Template::load('header_nav'); ?>
<div class="row">
	<div class="col-md-offset-1  col-md-8">
		<h2>formr documentation</h2>
		<p class="lead">
			chain simple forms into longer runs,
			use the power of R to generate pretty feedback and complex designs
		</p>
		<p>
			Most documentation is inside formr – you can just get going and it will be waiting for you where you need it. <br>
			If something just doesn't make sense or if you run into errors, <a href="https://github.com/rubenarslan/formr.org/issues" title="This link takes you to our Github issue tracker. Please give as much detail as you can.">please let us know</a>.
		</p>
	</div>
</div>
<div class="row">
	<div class="col-md-offset-1 col-md-8">
	
		<ul class="nav nav-tabs">
		  <li><a href="#run_module_explanations" data-toggle="tab">Run modules</a></li>
		  <li><a href="#sample_survey_sheet" data-toggle="tab">Survey spreadsheet</a></li>
		  <li><a href="#sample_choices_sheet" data-toggle="tab">Choices spreadsheet</a></li>
		  <li><a href="#available_items" data-toggle="tab">Item types</a></li>
		  <li class="active"><a href="#features" data-toggle="tab">Features</a></li>
		  <li><a href="#help" data-toggle="tab">Help</a></li>
		</ul>
	
		<div class="tab-content">
			<div class="tab-pane fade" id="run_module_explanations">
				<?php Template::load('run_module_explanations'); ?>
			</div>
			<div class="tab-pane fade" id="sample_survey_sheet">
                <?php Template::load('sample_survey_sheet'); ?>
			</div>
			<div class="tab-pane fade" id="sample_choices_sheet">
                <?php Template::load('sample_choices_sheet'); ?>
			</div>
			<div class="tab-pane fade" id="available_items">
                <?php Template::load('item_types'); ?>
			</div>
			<div class="tab-pane fade in active" id="features">
				<h4>
					Disclaimer
				</h4>
				<p> This is pretty much brand new software and is supplied for free, open-source. As such, it doesn't come with a warranty of any kind. Still, if you let us know when formr causes you trouble or headaches, we will try to help you resolve the problem and you will get our heartfelt apologies. If you're the technical type, you might consider hosting a stable release of formr yourself, because this version of formr tracks the most recent pre-release and will thus sometimes have kinks.</p>
				<h2>
					Features
				</h2>
				<h4>
					Good already:
				</h4>
				<ul class="fa-ul-more-padding">
					<li>
						does diary studies with automated reminders
					</li>
					<li>
						generates pretty feedback live, including <a href="http://ggplot2.org/">ggplot2</a>, <a href="http://cran.r-project.org/web/packages/lattice/">lattice</a> and <a href="http://rcharts.io/">rCharts</a> plots
					</li>
					<li>
						looks nice on your phone
					</li>
					<li>
						you can use R to do basically anything that R can do (complicated stuff!)
					</li>
					<li>
						manage access to and eligibility for studies
					</li>
					<li>
						longitudinal studies
					</li>
					<li>
						easily share, swap and combine surveys (they're simply spreadsheets with survey questions) and runs (so that you can share designs, e.g. "daily diary study" and higher-level components like filters and feedback)
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
						a dedicated <a href="https://github.com/rubenarslan/formr/">formr R package</a>: makes pretty feedback graphs and complex run  logic even simpler. Simplifies data wrangling (importing, aggregating, simulating data from surveys).
					</li>
					<li>
						a nice editor, <a href="https://github.com/ajaxorg/ace">Ace</a>, for editing Markdown &amp; R in runs.
					</li>
					
				</ul>
				<h4>
					Plans:
				</h4>
				<ul class="fa-ul-more-padding">
					<li>
						work offline on mobile phones and other devices with intermittent internet access (in the meantime <a href="https://enketo.org/">enketo</a> is pretty good and free too, but geared towards humanitarian aid)
					</li>

					<li>
						a better API (some basics are there)
					</li>
					<li>
						social networks, round robin studies - at the moment they are a bit bothersome to implement, but possible. There is a dedicated module already which might also get released as open source if there's time. 
					</li>
					<li>
						more <a href="https://github.com/rubenarslan/formr.org/issues?labels=enhancement&page=1&state=open">planned enhancements on Github</a>
					</li>
				</ul>
			</div>
			<div class="tab-pane fade active" id="help">
				<h4>Where to get help</h4>
				<p>If you're a participant in one of the studies implemented in formr, please reach out to the person running the study.</p>
				<p>If you're running a study yourself, there's several places to look.<p>
				<ul class="fa-ul-more-padding">
					<li>
						this documentation is a good start, just click on any of the tabs above.
					</li>
					<li>
						There is a <a href="https://github.com/rubenarslan/formr.org/wiki">Wiki</a> on Github. You can find a number of HowTos there and contribute yourself.
					</li>
					<li>
						You'll find answers to some <a href="https://github.com/rubenarslan/formr.org/wiki/FAQ---frequently-asked-questions">frequently asked questions</a> there too.
					</li>
					<li>
						You can <a href="https://groups.google.com/forum/#!forum/formr" title="you can ask and answer other admin users' questions here">ask and answer questions on our mailing list</a>.
					</li>
					<li>
						If you find a bug, <a href="https://github.com/rubenarslan/formr.org/issues">this is the place to describe it</a> (preferably in a way that allows us to reproduce it, but we're also accepting Yeti reports).
					</li>
				</ul>
			</div>
		</div>
	</div>
</div>

<?php Template::load('footer');
