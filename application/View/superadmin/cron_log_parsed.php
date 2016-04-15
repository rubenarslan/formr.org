<?php
    Template::load('header');
    Template::load('acp_nav');
?>	

<h2 class="drop_shadow">cron log</h2>

<div class="cron-log">
	<div class="files">
		<?php foreach ($files as $file => $path): ?>
		<div class="file <?= $file === $parse ? 'current' : '' ?>">
			<a href="<?php echo site_url('superadmin/cron_log?f='.$file); ?>">
				<i class="fa fa-file"></i> <?php echo $file; ?>
			</a>
		</div>

		<?php endforeach; ?>
	</div>
	<div id="log-entries" class="text panel-group opencpu_accordion">
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

<?php Template::load('footer'); ?>