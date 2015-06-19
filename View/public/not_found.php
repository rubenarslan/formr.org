<?php Template::load('header'); ?>

<div>
<div>
<div class="broken_tape">
	<div class="tape_label_box">
		<div class="tape_label">
			<?php $alerts = $site->renderAlerts();
			if($alerts) echo $alerts;
			else echo "Oh no! We can't find the page you're looking for (404).<br>";
			?> 
			<div class="tape_go_back">
				<a href="<?=WEBROOT?>">Maybe take it from the start?</a>
			</div>
			</div>
		</div>
</div>
<?php Template::load('footer');
