<?php Template::load('header_nav'); ?>
<div class="row">
	<div class="col-lg-12">
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
	<div class="col-lg-12">
	
		<ul class="nav nav-tabs">
		  <li><a href="#knitr_markdown" data-toggle="tab">Knitr &amp; Markdown</a></li>
		  <li class="active"><a href="#run_module_explanations" data-toggle="tab">Run modules</a></li>
		  <li><a href="#sample_survey_sheet" data-toggle="tab">Survey spreadsheet</a></li>
		  <li><a href="#sample_choices_sheet" data-toggle="tab">Choices spreadsheet</a></li>
		  <li><a href="#available_items" data-toggle="tab">Item types</a></li>
		  <li><a href="#r_helpers" data-toggle="tab">R helpers</a></li>
		  <li><a href="#features" data-toggle="tab">Features</a></li>
		  <li><a href="#help" data-toggle="tab">Help</a></li>
		</ul>
	
		<div class="tab-content">
			<div class="tab-pane fade" id="knitr_markdown">
				<?php Template::load('knitr_markdown'); ?>
			</div>
			<div class="tab-pane fade in active" id="run_module_explanations">
				<div class="row">
					<div class="col-lg-8 col-md-9">
						<?php Template::load('run_module_explanations'); ?>
					</div>
				</div>
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
			<div class="tab-pane fade" id="r_helpers">
                <?php Template::load('r_helpers'); ?>
			</div>
			<div class="tab-pane fade" id="features">
                <?php Template::load('features'); ?>
			</div>
			<div class="tab-pane fade active" id="help">
                <?php Template::load('get_help'); ?>
			</div>
		</div>
	</div>
</div>

<?php Template::load('footer');
