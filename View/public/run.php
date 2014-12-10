<?php Template::load('header', array('title' => $title, 'css' => $css, 'js' => $js)); ?>

<div class="row">
	<div class="col-lg-12 run_position_<?php echo $run_session->position; ?> run_unit_type_<?php echo $run_session->current_unit_type; ?> run_content">	
		<header class="run_content_header">
			<?php if ($run->header_image_path): ?>
				<img src="<?php echo $run->header_image_path; ?>" alt="<?php echo $run->name; ?>header image">
			<?php endif; ?>
		</header>

		<?php if ($alerts): ?>
		<div class="row">
			<div class="col-md-6 col-sm-6 all-alerts"><?php echo $alerts; ?></div>
		</div>
		<?php endif; ?>

		<?php echo $run_content; ?>
	</div>
</div>

<?php Template::load('footer');
