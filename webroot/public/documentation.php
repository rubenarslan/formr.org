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
				<table class='table table-striped'>
					<thead>
						<tr>
							<th>
								type
							</th>
							<th>
								name
							</th>
							<th>
								label
							</th>
							<th>
								optional
							</th>
							<th>
								showif
							</th>
							<th>
								optional
							</th>
							<th>
								class
							</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td>
								text
							</td>
							<td>
								name
							</td>
							<td>
								Please enter your name
							</td>
							<td>
							</td>
							<td>
							</td>
							<td>
							</td>
							<td>
							</td>
						</tr>
						
						<tr>
							<td>
								number 1,130,1
							</td>
							<td>
								age
							</td>
							<td>
								How old are you?
							</td>
							<td>
							</td>
							<td>
							</td>
							<td>
							</td>
							<td>
							</td>
						</tr>

						<tr>
							<td>
								mc agreement
							</td>
							<td>
								emotional_stability1R
							</td>
							<td>
								I worry a lot.
							</td>
							<td>
							</td>
							<td>
							</td>
							<td>
							</td>
							<td>
							</td>
						</tr>

						<tr>
							<td>
								mc agreement
							</td>
							<td>
								emotional_stability2R
							</td>
							<td>
								I easily get nervous and unsure of myself.
							</td>
							<td>
							</td>
							<td>
							</td>
							<td>
							</td>
							<td>
							</td>
						</tr>
						<tr>
							<td>
								mc agreement
							</td>
							<td>
								emotional_stability3
							</td>
							<td>
								I am relaxed and not easily stressed.
							</td>
							<td>
							</td>
							<td>
							</td>
							<td>
							</td>
							<td>
							</td>
						</tr>
					</tbody>
				</table>
				
			</div>
			<div class="tab-pane fade" id="sample_choices_sheet">
				<table class='table table-striped'>
					<thead>
						<tr>
							<th>
								list name
							</th>
							<th>
								name
							</th>
							<th>
								label
							</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td>
								agreement
							</td>
							<td>
								1
							</td>
							<td>
								disagree completely
							</td>
						</tr>
						<tr>
							<td>
								agreement
							</td>
							<td>
								2
							</td>
							<td>
								rather disagree
							</td>
						</tr>
						<tr>
							<td>
								agreement
							</td>
							<td>
								3
							</td>
							<td>
								neither agree nor disagree
							</td>
						</tr>
						<tr>
							<td>
								agreement
							</td>
							<td>
								4
							</td>
							<td>
								rather agree
							</td>
						</tr>
						<tr>
							<td>
								agreement
							</td>
							<td>
								5
							</td>
							<td>
								agree completely
							</td>
						</tr>
					</tbody>
					</table>
				</div>
				<div class="tab-pane fade" id="available_items">
					<h4>Plain display types</h4>
					<dl class="dl-horizontal">
						<dt>
							instruction
						</dt>
						<dd>
							 display text. instructions are displayed at least once and disappear only when there are no unanswered items left behind them (so putting an instruction directly before another ensures it will be displayed only once
						 </dd>
						 <dt>
							 submit
						 </dt>
						 <dd>
						 	display a submit button. No items are displayed after the submit button, until all of the ones preceding it have been answered. This is useful for pagination and to ensure that answers required for `showif` or for dynamically generating item text have been given. 
						 </dd>
					 </dl>
  					<h4>Simple input family</h4>
 					<dl class="dl-horizontal">
 						<dt>
							text <i>max_length</i>
						</dt>
						<dd>
							allows you to enter a text in a single-line input field. Adding a number `text 100` defines the maximum number of characters that may be entered.
						</dd>
 						<dt>
							textarea <i>max_length</i>
						</dt>
						<dd>
							displays a multi-line input field
						</dd>
 						<dt>
							number <i>min, max, step</i>
						</dt>
						<dd>
							for numbers. `step` defaults to `1`, using `any` will allow any decimals.
						</dd>
 						<dt>
							email
						</dt>
						<dd>
							for email addresses
						</dd>
					</dl>
					<h4>Sliders</h4>
 					<dl class="dl-horizontal">
 						<dt>
							range <i>min,max,step</i>
						</dt>
						<dd>
							these are sliders. The numeric value chosen is not displayed. Text to be shown to the left and right of the slider can be defined using the choice1 and choice2 fields. Defaults are `1,100,1`.
						</dd>
 						<dt>
							range_list <i>min,max,step</i>
						</dt>
						<dd>
							like range but the individual steps are visually indicated using ticks and the chosen number is shown to the right. 
						</dd>
					</dl>
					
					<h4>Datetime family</h4>
 					<dl class="dl-horizontal">
 						<dt>
							date
						</dt>
						<dd>
							for dates
						</dd>
 						<dt>
							time
						</dt>
						<dd>
							for times
						</dd>
					</dl>
 					<h4>Multiple choice family</h4>
 					<dl class="dl-horizontal">
 						<dt>
							mc <i>choice_list</i>
						</dt>
						<dd>
							multipe choice (radio buttons), you can choose only one.
						</dd>
 						<dt>
							mmc <i>choice_list</i>
						</dt>
						<dd>
							multiple multiple choice (check boxes), you can choose several. Choices defined as above
						</dd>
 						<dt>
							check
						</dt>
						<dd>
							a single check box for confirmation of a statement.
						</dd>
 						<dt>
							mc_button <i>choices</i>
						</dt>
						<dd>
							like `mc` but instead of the text appearing next to a small button, a big button contains the choice label
						</dd>
 						<dt>
							mmc_button
						</dt>
						<dd>
							like mmc and mc_button
						</dd>
 						<dt>
							check_button
						</dt>
						<dd>
							like check and mc_button
						</dd>
 						<dt>
							btnrating <i>min, max, step</i>
						</dt>
						<dd>
							This shows choice1 to the left, choice2 to the right and a series of buttons as defined by <code>min,max,step</code> in between. Defaults to 1,5,1.
						</dd>
 						<dt>
							sex
						</dt>
						<dd>
							shorthand for `mc_button` with the ♂, ♀ symbols as choices
						</dd>
						<dt>
							select <i>choice_list</i>
						</dt>
						<dd>
							a dropdown, you can choose only one
						</dd>
						<dt>
							select_multiple <i>choice_list</i>
						</dt>
						<dd>
							a list in which, you can choose several options
						</dd>
						<dt>
							select_or_add <i>choice_list, maxType</i>
						</dt>
						<dd>
							like select, but it allows users to choose an option not given. Uses <a href="http://ivaynberg.github.io/select2/">Select2</a>. <i>maxType</i> can be used to set an upper limit on the length of the user-added option. Defaults to 255.
						</dd>
						<dt>
							select_multiple_or_add<br> <i>choice_list, maxType, maxChoose</i>
						</dt>
						<dd>
							 like mselect and select_add, allows users to add options not given. <i>maxChoose</i> can be used to place an upper limit on the number of chooseable options.
						</dd>
						<dt>
							mc_heading <i>choice_list</i>
						</dt>
						<dd>
							This type permits you to show the labels for mc or mmc choices only once.<br>
							To get the necessary tabular look, assign a constant width to the choices (with classes), give the heading the same choices as the mcs, and give the following mcs (or mmcs)  the same classes + hide_label. 
						</dd>
			</dl>
				</div>
		</div>
	</div>
</div>
