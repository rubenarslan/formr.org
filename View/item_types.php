<h3>Item types</h3>
There are a lot of item types, in the beginning you will probably only need a few though. To see them in action,
try using the following <a href="<?=WEBROOT?>assets/example_surveys/all_widgets.xlsx">downloadable Excel file</a>. It contains example uses of nearly every item there is.
	<h4><i class="fa fa-fw fa-info"></i> Plain display types</h4>
	<dl class="dl-horizontal dl-wider">
		<dt>
			note
		</dt>
		<dd>
			 display text. Notes are displayed at least once and disappear only when there are no unanswered items left behind them (so putting a note directly before another ensures it will be displayed only once)
		 </dd>
		 <dt>
			 submit
		 </dt>
		 <dd>
		 	display a submit button. No items are displayed after the submit button, until all of the ones preceding it have been answered. This is useful for pagination and to ensure that answers required for <code>showif</code> or for dynamically generating item text have been given. 
		 </dd>
	 </dl>
	<h4><i class="fa fa-fw fa-keyboard-o"></i> Simple input family</h4>
	<dl class="dl-horizontal dl-wider">
		<dt>
			text <i>max_length</i>
		</dt>
		<dd>
			allows you to enter a text in a single-line input field. Adding a number <code>text 100</code> defines the maximum number of characters that may be entered.
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
			for numbers. <code>step</code> defaults to <code>1</code>, using <code>any</code> will allow any decimals.
		</dd>
		<dt>
			letters <i>max_length</i>
		</dt>
		<dd>
			like text, allows only letters (<code>A-Za-züäöß.;,!: </code>), no numbers.
		</dd>
		<dt>
			email
		</dt>
		<dd>
			for email addresses. They will be validated for syntax, but they won't be verified unless you say so in the run.
		</dd>
	</dl>
	<h4><i class="fa fa-fw fa-arrows-h"></i> Sliders</h4>
	<dl class="dl-horizontal dl-wider">
		<dt>
			range <i>min,max,step</i>
		</dt>
		<dd>
			these are sliders. The numeric value chosen is not displayed. Text to be shown to the left and right of the slider can be defined using the choice1 and choice2 fields. Defaults are <code>1,100,1</code>.
		</dd>
		<dt>
			range_ticks <i>min,max,step</i>
		</dt>
		<dd>
			like range but the individual steps are visually indicated using ticks and the chosen number is shown to the right. 
		</dd>
	</dl>
	
	<h4><i class="fa fa-fw fa-calendar"></i> Datetime family</h4>
	<dl class="dl-horizontal dl-wider">
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
	<h4><i class="fa fa-fw fa-magic"></i> Fancy family</h4>
	<dl class="dl-horizontal dl-wider">
		<dt>
			geopoint
		</dt>
		<dd>
			displays a button next to a text field. If you press the button (which has the location icon <i class="fa fa-location-arrow"></i> on it) and agree to share your location, the GPS coordinates will be saved. If you deny access or if GPS positioning fails, you can enter a location manually.
		</dd>
		<dt>
			color
		</dt>
		<dd>
			allows you to pick a color, using the operating system color picker (or one polyfilled by Webshims)
		</dd>
	</dl>
	<h4><i class="fa fa-fw fa-check-square"></i> Multiple choice family</h4>
	<p>The, by far, biggest family of items. Please note, that there is some variability in how the answers are stored. You need to know about this, if you (a) intend to analyse the data in a certain way, for example you will want to store numbers for Likert scale, but text for timezones or cities (b) if you plan to use conditions in the run or in showif or somewhere else where R is executed. (b) is especially important, because you might not notice if demographics$sex == 'male' never turns true because sex is stored as 0/1.</p>
	<dl class="dl-horizontal dl-wider">
		<dt>
			mc <i>choice_list</i>
		</dt>
		<dd>
			multipe choice (radio buttons), you can choose only one.
		</dd>
		<dt>
			mc_button <i>choices</i>
		</dt>
		<dd>
			like <code>mc</code> but instead of the text appearing next to a small button, a big button contains each choice label
		</dd>
		
		<dt>
			mc_multiple <i>choice_list</i>
		</dt>
		<dd>
			multiple multiple choice (check boxes), you can choose several. Choices defined as above.
		</dd>
		<dt>
			mc_multiple_button
		</dt>
		<dd>
			like mc_multiple and mc_button
		</dd>
		
		<dt>
			check
		</dt>
		<dd>
			a single check box for confirmation of a statement.
		</dd>
		<dt>
			check_button
		</dt>
		<dd>
			a bigger button to check.
		</dd>
		
		<dt>
			rating_button <br><i>min, max, step</i>
		</dt>
		<dd>
			This shows the choice1 label to the left, the choice2 label to the right and a series of numbered buttons as defined by <code>min,max,step</code> in between. Defaults to 1,5,1.
		</dd>
		<dt>
			sex
		</dt>
		<dd>
			shorthand for <code>mc_button</code> with the ♂, ♀ symbols as choices
		</dd>
		<dt>
			select_one <i>choice_list</i>
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
			select_or_add <br><i>choice_list, maxType</i>
		</dt>
		<dd>
			like select_one, but it allows users to choose an option not given. Uses <a href="http://ivaynberg.github.io/select2/">Select2</a>. <i>maxType</i> can be used to set an upper limit on the length of the user-added option. Defaults to 255.
		</dd>
		<dt>
			select_or_add_multiple <br><i>choice_list, maxType, <br>maxChoose</i>
		</dt>
		<dd>
			 like select_multiple and select_or_add_one, allows users to add options not given. <i>maxChoose</i> can be used to place an upper limit on the number of chooseable options.
		</dd>
		<dt>
			mc_heading <i>choice_list</i>
		</dt>
		<dd>
			This type permits you to show the labels for mc or mc_multiple choices only once.<br>
			To get the necessary tabular look, assign a constant width to the choices (with classes), give the heading the same choices as the mcs, and give the following mcs (or mc_multiples)  the same classes + hide_label. 
		</dd>
</dl>



<h4><i class="fa fa-fw fa-eye-slash"></i> Hidden family</h4>
These items don't require the user to do anything, so including them simply means that the relevant value will be stored. If you have exclusively hidden items in a form, things will wrap up immediately and move to the next element in the run. This can be useful for hooking up with other software which sends data over the query string i.e. https://formr.org/run_name?param1=10&user_id=29
<dl class="dl-horizontal dl-wider">
	<dt>
		ip
	</dt>
	<dd>
		saves your IP address. You should probably not do this covertly but explicitly announce it.
	</dd>
	<dt>
		referrer
	</dt>
	<dd>
		saves the last outside referrer (if any), ie. from which website you came to formr
	</dd>
	<dt>
		server var
	</dt>
	<dd>
		 saves the <a href="http://us1.php.net/manual/en/reserved.variables.server.php">$_SERVER</a> value with the index given by var. Can be used to store one of 'HTTP_USER_AGENT', 'HTTP_ACCEPT', 'HTTP_ACCEPT_CHARSET', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_CONNECTION', 'HTTP_HOST', 'QUERY_STRING', 'REQUEST_TIME', 'REQUEST_TIME_FLOAT'. In English: the browser, some stuff about browser language information, some server stuff, and access time.
	</dd>
	<dt>
		get var
	</dt>
	<dd>
		 saves the <code>var</code> from the query string, so in the example above <code>get <em>param1</em></code> would lead to 10 being stored.
	</dd>
	<dt>
		random min,max
	</dt>
	<dd>
		generates <a href="http://php.net/mt_rand">a random number</a> for later use (e.g. randomisation in experiments). Minimum and maximum default to 0 and 1 respectively. If you specify them, you have to specify both. You will probably prefer to do your shuffling using the run module Shuffle.
	</dd>

</dl>