<?php Template::load('header_nav'); ?>

<?php if($runs) : ?>

<div class="row">
	<div class="col-md-12">
		<h3><i class="fa fa-file"></i> <?=_('Publications')?> 		<small>using data collected using the formr.org software</small></h3>
		<?php Template::load('public/publications'); ?>

		<h3><i class="fa fa-file-archive-o"></i> <?=_('Studies')?></h3>
		<p><i>studies currently running on formr.org</i></p>
		<div class="row">
        <?php $i = 1; foreach($runs as $run) : ?>
            <div class="col-md-3 study-box">
                <h4 class="study-box-headline"><a href="<?php echo run_url($run['name']); ?>"><?php echo ($run['title'] ? $run['title'] : $run['name']); ?></a></h4>
				<div class="blurb">
					
					<?php
						if ($run['public_blurb_parsed']) {
							echo $run['public_blurb_parsed'];
							echo '<p>&nbsp;</p>';
						}
					?>
					<br />
					<i class="fa fa-copy"></i> <?php echo $run['name']; ?> <br />
					<i class="fa fa-link"></i> <a href="<?php echo run_url($run['name']); ?>"><?php echo run_url($run['name']); ?></a>
				</div>
				<div class="open">
					<a href="<?php echo run_url($run['name']); ?>" class="btn btn-primary">Participate <i class="fa fa-users"></i></a>
					<?php Template::load('public/social_share', array('title' => $run['name'], 'url' => run_url($run['name']))); ?>
				</div>
            </div>
			<?php if ($i > 1 && $i %3 == 0){ echo '</div><div class="row">'; } ?>
         <?php $i++; endforeach; ?>
		</div>

	</div>
</div>

<?php endif; ?>

<?php Template::load('footer'); ?>
