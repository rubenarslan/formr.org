<?php
Template::load('header');
Template::load('acp_nav');
?>
<div class="row">
	<?php if (!empty($overview_script)): ?>
	<div class="col-lg-12">
		<h1><i class="fa fa-eye"></i> Run overview <small><?=$overview_script->title?></small></h2>
		<h2>
			<?=$user_overview['users_finished']?>  finished users,
			<?=$user_overview['users_active']?> active users, 
			<?=$user_overview['users_waiting']?> <abbr title="inactive for at least a week">waiting</abbr> users
		</h2>
		<?php echo $overview_script->parseBodySpecial(); ?>
	</div>
	<?php else: ?>
		<h1><i class="fa fa-eye"></i> Run overview</h1>
		<div class="col-lg-12"> Please <a href="<?= admin_run_url($run->name, 'settings')?>">add an overview script</a>
	<?php endif ;?>
</div>

<?php Template::load('footer');
