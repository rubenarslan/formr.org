<?php 
	Template::load('public/header', array(
		'headerClass' => 'fmr-small-header',
	)); 
?>

<section id="fmr-projects" style="padding-top: 2em; background: #fff">
	<div class="container">
		<div class="row">
			<div class="col-md-12">
				<h2>formR documentation</h2>
				<p class="lead">
					chain simple forms into longer runs,
					use the power of R to generate pretty feedback and complex designs
				</p>
				<p>
					Most documentation is inside formr – you can just get going and it will be waiting for you where you need it. <br>
					If something just doesn't make sense or if you run into errors, <a href="https://github.com/rubenarslan/formr.org/issues" title="" data-original-title="This link takes you to our Github issue tracker. Please give as much detail as you can.">please let us know</a>.
				</p>
			</div>
			<div class="col-md-2 documentation-toc">
				<h4>Contents</h4>
				<hr />
				<ul class="list-unstyled nav nav-tabs">
					<li class="active"><a href="#get_started" data-toggle="tab" aria-expanded="true">Getting Started</a></li>
					<li><a href="#run_module_explanations" data-toggle="tab">Runs &amp; Run Modules</a></li>
					<li><a href="#sample_survey_sheet" data-toggle="tab" aria-expanded="false">Survey Spreadsheet</a></li>
					<li><a href="#sample_choices_sheet" data-toggle="tab" aria-expanded="false">Choices Spreadsheet</a></li>
					<li><a href="#available_items" data-toggle="tab" aria-expanded="false">Item types</a></li>
					<li><a href="#knitr_markdown" data-toggle="tab" aria-expanded="false">Knit R &amp; Markdown</a></li>
					<li><a href="#r_helpers" data-toggle="tab" aria-expanded="false">R Helpers</a></li>
					<li><a href="#features" data-toggle="tab" aria-expanded="false">Features</a></li>
					<li><a href="#api" data-toggle="tab" aria-expanded="false">API</a></li>
					<li><a href="#help" data-toggle="tab" aria-expanded="false">Help</a></li>
				</ul>

			</div>
			<div class="col-md-10 documentation-content tab-content">
				<div id="get_started" class="tab-pane fade active in">
					<?php Template::load('public/documentation/getting_started'); ?>
				</div>
				<div id="run_module_explanations" class="tab-pane fade">
					<?php Template::load('public/documentation/run_module_explanations'); ?>
				</div>
				<div class="tab-pane fade" id="sample_survey_sheet">
					<?php Template::load('public/documentation/sample_survey_sheet'); ?>
				</div>
				<div class="tab-pane fade" id="sample_choices_sheet">
					<?php Template::load('public/documentation/sample_choices_sheet'); ?>
				</div>
				<div class="tab-pane fade" id="available_items">
					<?php Template::load('public/documentation/item_types'); ?>
				</div>
				<div class="tab-pane fade" id="knitr_markdown">
					<?php Template::load('public/documentation/knitr_markdown'); ?>
				</div>
				<div class="tab-pane fade" id="r_helpers">
					<?php Template::load('public/documentation/r_helpers'); ?>
				</div>
				<div class="tab-pane fade" id="features">
					<?php Template::load('public/documentation/features'); ?>
				</div>
				<div class="tab-pane fade" id="api">
					<?php Template::load('public/documentation/api'); ?>
				</div>
				<div class="tab-pane fade" id="help">
					<?php Template::load('public/documentation/get_help'); ?>
				</div>
			</div>
		</div>
	</div>
</section>

<?php Template::load('public/newsletter'); ?>

<?php Template::load('public/footer'); ?>
			
