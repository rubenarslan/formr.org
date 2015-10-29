<?php Template::load('header_nav'); ?>

<?php if($runs) : ?>

<div class="row">
	<div class="col-md-12">
		<h2><?=_("Current studies:")?></h2>
		<div class="row">
        <?php foreach($runs as $run) : ?>
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
         <?php endforeach; ?>
		</div>

	</div>
</div>

<?php endif; ?>

<?php Template::load('footer'); ?>
