<nav class="main_admin_nav navbar navbar-default navbar-formr" role="navigation">
    <!-- Brand and toggle get grouped for better mobile display -->
     <div class="navbar-header">
       <button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#public-nav-collapse" title="toggle navigaton">
         <span class="sr-only">Toggle navigation</span>
         <i class="fa fa-bars"></i>
       </button>
       <a class="navbar-brand" href="<?=WEBROOT?>" title="Go to the public area"><i class="fa fa-circle fa-fw"></i> formr</a>
   	
     </div>

	  <div class="collapse navbar-collapse" id="public-nav-collapse">
		<ul class="nav navbar-nav">
			    <li <?php
				echo endsWith($_SERVER['PHP_SELF'],'admin/index.php')?' class="active"':''?>><a href="<?=WEBROOT?>admin/">
				<i class="fa fa-eye-slash fa-fw"></i>
				admin
			</a></li>   
	
		
		  		<li class="dropdown <?=(strpos($_SERVER['PHP_SELF'],'/admin/survey/') OR strpos($_SERVER['PHP_SELF'],'/admin/survey/'))?'active':''?>">
		  			<a class="dropdown-toggle"
		  			data-toggle="dropdown"
		  			href="#">
		  				<i class="fa fa-pencil-square fa-fw"></i> surveys
		  				<b class="caret"></b>
		  			</a>
				  <ul class="dropdown-menu">
			  		<li <?=endsWith($_SERVER['PHP_SELF'],'add_study.php')?' class="active"':''?>>
			  			<a href="<?=WEBROOT?>admin/survey/"><?php echo _("create new survey"); ?></a>
			  		</li>
				<?php
				$studies = $user->getStudies();
				if($studies) {
					echo '<li class="divider"></li>';
			
				  foreach($studies as $menu_study) {
				    echo "<li>
						<a href='".WEBROOT."admin/survey/".$menu_study['name']."/'>".$menu_study['name']."</a>
					</li>";
				  }
				}
				?>
				</ul>
			</li>
			<li class="dropdown <?=(strpos($_SERVER['PHP_SELF'],'/admin/run/') OR strpos($_SERVER['PHP_SELF'],'/run/'))?'active':''?>">
				<a class="dropdown-toggle"
				data-toggle="dropdown"
				href="#">
					<i class="fa fa-rocket fa-fw"></i> runs
					<b class="caret"></b>
				</a>
			  <ul class="dropdown-menu">
		  		<li <?=endsWith($_SERVER['PHP_SELF'],'add_run.php')?' class="active"':''?>>
		  			<a href="<?=WEBROOT?>admin/run/"><?php echo _("create new run"); ?></a>
		  		
				<?php
				$runs = $user->getRuns();
				if($runs) {
					echo '<li class="divider"></li>';
			
				  foreach($runs as $menu_run) {
				    echo '<li>
						<a href="'.WEBROOT.'admin/run/'.$menu_run['name'].'/">'.$menu_run['name'].'</a>
					</li>';
				  }
				}
				?>
				</ul>
			</li>
	
			<li class="dropdown <?=strpos($_SERVER['PHP_SELF'],'/mail/')?'active':''?>">
				<a class="dropdown-toggle"
				data-toggle="dropdown"
				href="#">
					<i class="fa fa-envelope fa-fw"></i> mail
					<b class="caret"></b>
				</a>
			  <ul class="dropdown-menu">
		  		<li <?=endsWith($_SERVER['PHP_SELF'],'mail/index.php')?' class="active"':''?>>
					<a href="<?=WEBROOT?>admin/mail/">
						<?php echo _("list & add accounts"); ?>
					</a>
				</li>
		  		<li <?=endsWith($_SERVER['PHP_SELF'],'mail/log.php')?' class="active"':''?>>
					<a href="<?=WEBROOT?>admin/mail/log">
						<?php echo _("email log"); ?>
					</a>
				</li>
				<?php
				$accs = $user->getEmailAccounts();
				if($accs) {
					echo '<li class="divider"></li>';
			
				  foreach($accs as $menu_acc) {
				    echo '<li'.((isset($_GET['account_id']) AND $menu_acc['id']==$_GET['account_id'])?' class="active"':'').'>
						<a href="'.WEBROOT.'admin/mail/edit/?account_id='.$menu_acc['id'].'">'.$menu_acc['from'].'</a>
					</li>';
				  }
				}
				?>
				</ul>
			</li>
	
				<li class="dropdown">
					<a class="dropdown-toggle"
					data-toggle="dropdown"
					href="#">
						<i class="fa fa-cog fa-fw"></i> more
						<b class="caret"></b>
					</a>
					<ul class="dropdown-menu">
						<li <?=endsWith($_SERVER['PHP_SELF'],'cron_log.php')?' class="active"':''?>>
							<a href="<?=WEBROOT?>admin/cron_log">
								<i class="fa fa-cog"></i>
								cron job log
							</a>
						</li>
						<li>
							<a href="https://github.com/rubenarslan/formr">
								<i class="fa fa-github-alt fa-fw"></i>
								Github repository
							</a>
						</li>
						<?php if($user->isSuperAdmin()): ?>
						<li>
						    <a href="<?=WEBROOT?>admin/user_management">
								<i class="fa fa-users fa-fw"></i>
								<?php echo _("manage users"); ?>
							</a>
						</li>
					   <?php endif;	?>
					</ul>
				</li>

				<li><a href="<?=WEBROOT?>public/logout"><i class="fa fa-sign-out fa-fw"></i> log out</a></li>
			</ul>
			<ul class="nav navbar-nav navbar-right">
				<li>
				    <a href="<?=WEBROOT?>/">
						<i class="fa fa-eye fa-fw"></i>
						<?php echo _("go to public area"); ?>
					</a>
				</li>
				<li>
				    <a href="<?=WEBROOT?>public/documentation">
						<i class="fa fa-question-circle fa-fw"></i>
					</a>
				</li>
			</ul>
		</div>
</nav>

<?php if(isset($study)): ?>

<?php
$resultCount = $study->getResultCount();
?>
<div class="row">
	<div class="col-lg-12">
		<h3><i class="fa fa-pencil-square"></i> <?php echo $study->name;?> <small><?= ($resultCount['begun']+$resultCount['finished'])?> results</small></h3>
	</div>
</div>

<div class="row">
	<nav class="col-lg-2 col-md-2 col-sm-3">
		<ul class="fa-ul fa-ul-more-padding">
			<li <?=endsWith($_SERVER['PHP_SELF'],'survey/access.php')?' class="active"':''?>>
				<a href="<?=WEBROOT?>admin/survey/<?php echo $study->name; ?>/access">
					<i class="fa-li fa fa-play"></i> <?php echo _("Test study"); ?></a>
			</li>
		

	<li <?=endsWith($_SERVER['PHP_SELF'],'admin/survey/index.php')?' class="active"':''?>>
		<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/index"><i class="fa-li fa fa-cogs"></i> Settings</a>
	</li>
	<li <?=endsWith($_SERVER['PHP_SELF'],'admin/survey/upload_items.php')?' class="active"':''?>>
		<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/upload_items"><i class="fa-li fa fa-table"></i> Import items</a>
	</li>

	<li <?=endsWith($_SERVER['PHP_SELF'],'admin/survey/show_item_table.php')?' class="active"':''?>>
		<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/show_item_table"><i class="fa-li fa fa-th"></i> Item table</a>
	</li>

	<li <?=endsWith($_SERVER['PHP_SELF'],'admin/survey/show_results.php')?' class="active"':''?>>
		<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/show_results"><i class="fa-li fa fa-tasks"></i> Show results</a>
	</li>

	<li class="dropdown">
	    <a class="dropdown-toggle"
	       data-toggle="dropdown"
	       href="#">
	        <i class="fa-li fa fa-floppy-o"></i> Export results
	      </a>
	    <ul class="dropdown-menu">
			<li>
			
				<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_csv"><i class="fa fa-floppy-o"></i> Download CSV</a>
			</li>
			<li>
				<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_csv_german"><i class="fa fa-floppy-o"></i> Download German CSV</a>
			</li>
			<li>
				<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_tsv"><i class="fa fa-floppy-o"></i> Download TSV</a>
			</li>
			<li>
				<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_xls"><i class="fa fa-floppy-o"></i> Download XLS</a>
			</li>
			<li>
				<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_xlsx"><i class="fa fa-floppy-o"></i> Download XLSX</a>
			</li>
		
	    </ul>
	  </li>

	<li class="nav-header"><i class="fa-li fa fa-bolt"></i> Danger Zone</li>

	<li <?=endsWith($_SERVER['PHP_SELF'],'admin/survey/delete_study.php')?' class="active"':''?>>
		<a class="hastooltip" title="Go to deletion dialog, does not delete yet" href="<?=WEBROOT?>admin/survey/<?=$study->name?>/delete_study">
			<i class="fa-li fa fa-trash-o"></i> Delete study</a>
	</li>

	<li <?=endsWith($_SERVER['PHP_SELF'],'admin/survey/delete_results.php')?' class="active"':''?>>
		<a class="hastooltip" title="Go to deletion dialog, does not delete yet" href="<?=WEBROOT?>admin/survey/<?=$study->name?>/delete_results">
			<i class="fa-li fa fa-eraser"></i> Delete results</a>
	
	</li>

	</ul>

	</nav>
<?php endif; ?>


<?php if(isset($run)): ?>
<div class="row run_header">
	<div class="col-lg-12">
		<h1><i class="fa fa-rocket"></i> <?php echo $run->name;?></h1>
	</div>
</div>	
<div class="row">
	<nav class="col-lg-2 col-md-2 col-sm-3">
		<ul class="fa-ul  fa-ul-more-padding">
			<li>
				<a href="<?=WEBROOT?><?php echo $run->name; ?>" title="It is best to test the run in a new private/incognito window, this is possible in Chrome &amp; Firefox. That way, you'll start fresh. You need to publish the run, so that this works. If you test using your account, remember that the run saves your progress, so you might have to reset yourself using the user overview.">
					<i class="fa-li fa fa-play"></i> <?php echo _("Test run"); ?></a>
			</li>
			
			<li <?=endsWith($_SERVER['PHP_SELF'],'run/index.php')?' class="active"':''?>>
				<a href="<?=WEBROOT?>admin/run/<?php echo $run->name; ?>/"><i class="fa-li fa fa-pencil"></i> <?php echo _("Edit run"); ?></a>
			</li>

			<li <?=endsWith($_SERVER['PHP_SELF'],'run/user_overview.php')?' class="active"':''?> title="Here you can monitor users' progress, send them to a different position and send them manual reminders.">
				<a href="<?=WEBROOT?>admin/run/<?php echo $run->name; ?>/user_overview"><i class="fa-li fa fa-users"></i> <?php echo _("User Overview"); ?></a>
			</li>
			<li <?=endsWith($_SERVER['PHP_SELF'],'run/user_detail.php')?' class="active"':''?> title="Here you'll see users' entire history of participation, i.e. when they left which position etc.">
				<a href="<?=WEBROOT?>admin/run/<?php echo $run->name; ?>/user_detail"><i class="fa-li fa fa-search"></i> <?php echo _("User Detail"); ?></a>
			</li>
			
			<li <?=endsWith($_SERVER['PHP_SELF'],'run/random_groups.php')?' class="active"':''?> title="This is simply your overview of how users have been randomised.">
				<a href="<?=WEBROOT?>admin/run/<?php echo $run->name; ?>/random_groups"><i class="fa-li fa fa-random"></i> <?php echo _("Random groups"); ?></a>
			</li>
			
			<li <?=endsWith($_SERVER['PHP_SELF'],'run/upload_files.php')?' class="active"':''?> title="Upload images, videos, sounds and the like.">
				<a href="<?=WEBROOT?>admin/run/<?php echo $run->name; ?>/upload_files"><i class="fa-li fa fa-upload"></i> <?php echo _("Upload files"); ?></a>
			</li>
			
			<li class="nav-header"><i class="fa-li fa fa-bolt"></i> Danger Zone</li>
			
			<li <?=endsWith($_SERVER['PHP_SELF'],'admin/run/delete_run.php')?' class="active"':''?>>
				<a class="hastooltip" title="Go to deletion dialog, does not delete yet" href="<?=WEBROOT?>admin/run/<?=$run->name?>/delete_run">
					<i class="fa-li fa fa-trash-o"></i> Delete run</a>
			</li>
			
			<li <?=endsWith($_SERVER['PHP_SELF'],'admin/run/rename_run.php')?' class="active"':''?>>
				<a class="hastooltip" title="Rename your run, but be careful, this also changes the link." href="<?=WEBROOT?>admin/run/<?=$run->name?>/rename_run">
					<i class="fa-li fa fa-unlock"></i> Rename run</a>
			</li>

		</ul>
	</nav>
<?php endif; ?>

<?php if(!isset($study) AND !isset($run)): ?>
	<div class="col-md-12">
<?php else: ?>
	<div class="col-lg-10 col-md-10 col-sm-9 main_body">
<?php endif; ?>

<?php 
$alerts = $site->renderAlerts();
if(!empty($alerts)):
	echo '
		<div class="row">
			<div class="col-md-6 col-sm-6 all-alerts">';
				echo $alerts;
		echo '</div>
		</div>';
endif;
?>