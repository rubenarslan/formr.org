<?php
    Template::load('header');
    Template::load('acp_nav');
?>
<div class="row">
	<div class="col-md-8">
		<h2>runs &amp; surveys <small>what's the difference?</small></h2>
		<p>
			<strong>Surveys</strong> are meant to be simple. They can be completed in one session (though they can reference and use information gathered in other surveys). 
			Surveys are never accessible on their own, they are always part of a (sometimes simple) run. They are created through spreadsheets containing survey items.
		<p>
			<strong>Runs</strong> allow you to chain simple surveys, together with other modules: emails, branches, pauses, etc. You can make simple one-shot surveys with a pretty R-generated feedback plot at the end. You could use them for looped surveys: training studies, diaries or experience sampling. You can use them to permit access only to users who fulfill certain criteria.
		</p>
		<p>
			<strong>Need help?</strong> You have <a href="<?=site_url('public/documentation#help')?>">several options</a>.
		</p>
		<div class="row">
			<?php if ($studies): ?>
			<div class="col-sm-6">
				<h3>Surveys</h3>
				<ul class="fa-ul">
					<?php foreach ($studies as $menu_study) : ?>
						<li><a href="<?php echo admin_study_url($menu_study['name']); ?>"> <i class='fa fa-pencil-square fa-fw'></i> <?php echo $menu_study['name']; ?></a></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>
			
			<?php if ($runs): ?>
			<div class="col-sm-6">
				<h3>Runs</h3>
				<ul class="fa-ul">
					<?php foreach ($runs as $menu_run) : ?>
						<li><a href="<?php echo admin_run_url($menu_run['name']); ?>"> <i class='fa fa-pencil-square fa-fw'></i> <?php echo $menu_run['name']; ?></a></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>
		</div>
	</div>
</div>
<?php Template::load('footer');