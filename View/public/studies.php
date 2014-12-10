<?php Template::load('header_nav'); ?>

<?php if($runs) : ?>

<div class="row">
	<div class="col-lg-6 col-lg-offset-1 col-sm-5 col-sm-offset-1 col-xs-12">
		<h2><?=_("Current studies:")?></h2>
        <?php foreach($runs as $run) : ?>
        <div class="row">
            <div class="col-lg-12 well">
                <h4><a href="<?php echo run_url($run['name']); ?>"><?php echo ($run['title'] ? $run['title'] : $run['name']); ?></a></h4>
                <?php echo $run['public_blurb_parsed']; ?>
            </div>
        </div>
         <?php endforeach; ?>

	</div>
</div>

<?php endif; ?>

<?php Template::load('footer'); ?>
