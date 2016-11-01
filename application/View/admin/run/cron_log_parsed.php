<?php
    Template::load('header');
    Template::load('acp_nav');
?>	
<h2>cron log</h2>
<p>
	The cron job runs every x minutes, to evaluate whether somebody needs to be sent a mail. This usually happens if a pause is over. It will then skip forward or backward, send emails and shuffle participants, but will stop at surveys and pages, because those should be viewed by the user.
</p>


<div class="cron-log">
	<div id="log-entries" class="log-entries panel-group opencpu_accordion">
		<?php
			if ($parse) {
				$parser->printCronLogFile($parse);
			}
		?>
	</div>
</div>

<script>
	$(document).ready(function() {
		var $entries = $('#log-entries');
		var items = $entries.children('.log-entry');
		$entries.append(items.get().reverse());
		$entries.show();
	});
</script>
	
<?php Template::load('footer');