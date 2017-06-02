<?php 
	Template::load('public/header', array(
		'headerClass' => 'fmr-small-header',
	)); 
?>

<section id="fmr-projects" style="padding-top: 2em;">
	<div class="container">
		<div class="row row-bottom-padded-md">
			<div class="col-md-6 col-md-offset-3 text-center">
				<h2 class="fmr-lead animate-box">Studies</h2>
				<p class="fmr-sub-lead animate-box">Some studies currently running on formr.</p>
			</div>
		</div>
		<div class="row">

			<?php foreach ($runs as $run) : ?>
			<div class="col-md-4 col-xxs-12">
				<div class="fmr-project-item study-box">
					<h2><a href="<?php echo run_url($run['name']); ?>"><?php echo ($run['title'] ? $run['title'] : $run['name']); ?></a></h2>
					<div class="blurb col-md-12">
						<?php echo !empty($run['public_blurb_parsed']) ? $run['public_blurb_parsed'] : '<p class="empty-study-blurb">&nbsp;</p>' ?>
					</div>
					<div class="col-md-12">
						<i class="fa fa-copy"></i> <?php echo $run['name']; ?> <br>
						<i class="fa fa-link"></i> <a href="<?php echo run_url($run['name']); ?>"><?php echo run_url($run['name']); ?></a>
					</div>
					<div class="study-box-action">
						<a href="<?php echo run_url($run['name']); ?>" class="btn btn-primary">Participate <i class="fa fa-users"></i></a>
						<div class="share pull-right social-share">
							<?php Template::load('public/social_share', array('title' => $run['name'], 'url' => run_url($run['name']))); ?>
						</div>
					</div>
				</div>
			</div>
			<?php endforeach; ?>

		</div>
	</div>
</section>
<!-- END #fmr-projects -->

<section id="fmr-features">
	<div class="container">
		<div class="row text-center row-bottom-padded-md">
			<div class="col-md-8 col-md-offset-2">
				<h2 class="fmr-lead animate-box">Publications</h2>
				<p class="fmr-sub-lead animate-box">Publications using data collected using the formr.org software</p>
			</div>
		</div>
		<div class="row">
			<div class="col-md-12">
				<?php Template::load('public/publications'); ?>
			</div>
		</div>
	</div>
</section>

<?php Template::load('public/footer'); ?>
