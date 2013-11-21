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
			Most documentation is inside formr – you can just get going and it will be waiting for you where you need it.<br>
			Here, we have collated some information on our modules to give you a preview of formr's feature set.
		</p>
	</div>
</div>
<div class="row">
	<div class="col-md-8">
	
		<ul class="nav nav-tabs">
		  <li class="active"><a href="#run_module_explanations" data-toggle="tab">Run modules</a></li>
		  <li><a href="#sample_survey_sheet" data-toggle="tab">A sample survey spreadsheet</a></li>
		  <li><a href="#sample_choices_sheet" data-toggle="tab">A sample choices spreadsheet</a></li>
		  <li><a href="#available_items" data-toggle="tab">Available item types</a></li>
		</ul>
	
		<div class="tab-content">
			<div class="tab-pane fade in active" id="run_module_explanations">
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
		</div>
	</div>
</div>
