<?php
require_once '../../define_root.php';
require_once INCLUDE_ROOT."Model/Site.php";

require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/public_nav.php";


require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/public_nav.php";
?>
<div class="row">
	<div class="col-md-8">
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
	<div class="col-md-8">
	
		<ul class="nav nav-tabs">
		  <li><a href="#run_module_explanations" data-toggle="tab">Run modules</a></li>
		  <li><a href="#sample_survey_sheet" data-toggle="tab">Survey spreadsheet</a></li>
		  <li><a href="#sample_choices_sheet" data-toggle="tab">Choices spreadsheet</a></li>
		  <li><a href="#available_items" data-toggle="tab">Item types</a></li>
		  <li class="active"><a href="#features" data-toggle="tab">Features</a></li>
		</ul>
	
		<div class="tab-content">
			<div class="tab-pane fade" id="run_module_explanations">
					<?php
					require INCLUDE_ROOT.'View/run_module_explanations.php';	
					?>
			</div>
			<div class="tab-pane fade" id="sample_survey_sheet">
				<?php
				require INCLUDE_ROOT.'View/sample_survey_sheet.php';	
				?>
			</div>
			<div class="tab-pane fade" id="sample_choices_sheet">
				<?php
				require INCLUDE_ROOT.'View/sample_choices_sheet.php';	
				?>
			</div>
			<div class="tab-pane fade" id="available_items">
				<?php
				require INCLUDE_ROOT.'View/item_types.php';	
				?>

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
						generates pretty feedback "live", including ggplot2 &amp; lattice plots
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
						easily share, swap and combine surveys (they're simply spreadsheets with survey questions)
					</li>
					<li>
						works on all somewhat modern devices and degrades gracefully where it doesn't
					</li>
					<li>
						formats text using Github-flavoured Markdown (a.k.a. the easiest and least bothersome way to mark up text)
					</li>
					<li>
						a nice editor, <a href="https://github.com/ajaxorg/ace">Ace</a>, for editing Markdown &amp; R in runs.
					</li>
					<li>
						file, image, video, sound uploads for users (as survey items) and admins (to supply study materials)
					</li>
					<li>
						complex conditional items
					</li>
					<li>
						a dedicated <a href="https://github.com/rubenarslan/formr/">formr R package</a>: makes pretty feedback graphs and complex run  logic even simpler. Simplifies data munging stuff (importing, aggregating, simulating data from surveys).
					</li>
					
				</ul>
				<h4>
					Plans:
				</h4>
				<ul class="fa-ul-more-padding">
					<li>
						send text messages (will only get simpler, you can already do this using R and httr)
					</li>
					<li>
						work offline on mobile phones and other devices with intermittent internet access (in the meantime <a href="https://enketo.org/">enketo</a> is pretty good and free too, but geared towards humanitarian aid)
					</li>
					<li>
						easily share, swap and combine runs (so that you can share designs, e.g. "daily diary study with one reminder", and add higher-level components like filters with one click)
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
				<h4>
					Might be nice:
				</h4>
				<ul class="fa-ul-more-padding">
					<li>
						use as app on Apple and Android devices to be able to use more OS functionality
					</li>
					<li>
						supporting Pushover's API (or something similar) to send push messages to a phone. You could already do this easily in an R call, so no hurry here.
					</li>
				</ul>
			</div>
		</div>
	</div>
</div>

<?php
require_once INCLUDE_ROOT . "View/footer.php";