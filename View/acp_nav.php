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
				echo endsWith($_SERVER['SCRIPT_NAME'],'admin/index.php')?' class="active"':''?>><a href="<?=WEBROOT?>admin/">
				<i class="fa fa-eye-slash fa-fw"></i>
				admin
			</a></li>   
	
		
		  		<li class="dropdown <?=(strpos($_SERVER['SCRIPT_NAME'],'/admin/survey/') OR strpos($_SERVER['SCRIPT_NAME'],'/admin/survey/'))?'active':''?>">
		  			<a class="dropdown-toggle"
		  			data-toggle="dropdown"
		  			href="#">
		  				<i class="fa fa-pencil-square fa-fw"></i> surveys
		  				<b class="caret"></b>
		  			</a>
				  <ul class="dropdown-menu">
			  		<li <?=endsWith($_SERVER['SCRIPT_NAME'],'add_survey.php')?' class="active"':''?>>
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
			<li class="dropdown <?=(strpos($_SERVER['SCRIPT_NAME'],'/admin/run/') OR strpos($_SERVER['SCRIPT_NAME'],'/run/'))?'active':''?>">
				<a class="dropdown-toggle"
				data-toggle="dropdown"
				href="#">
					<i class="fa fa-rocket fa-fw"></i> runs
					<b class="caret"></b>
				</a>
			  <ul class="dropdown-menu">
		  		<li <?=endsWith($_SERVER['SCRIPT_NAME'],'add_run.php')?' class="active"':''?>>
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
	
			<li class="dropdown <?=strpos($_SERVER['SCRIPT_NAME'],'/mail/')?'active':''?>">
				<a class="dropdown-toggle"
				data-toggle="dropdown"
				href="#">
					<i class="fa fa-envelope fa-fw"></i> mail
					<b class="caret"></b>
				</a>
			  <ul class="dropdown-menu">
		  		<li <?=endsWith($_SERVER['SCRIPT_NAME'],'mail/index.php')?' class="active"':''?>>
					<a href="<?=WEBROOT?>admin/mail/">
						<?php echo _("list & add accounts"); ?>
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
						<li>
							<a href="https://github.com/rubenarslan/formr.org">
								<i class="fa fa-github-alt fa-fw"></i>
								Github repository
							</a>
						</li>
						<li>
							<a href="https://github.com/rubenarslan/formr.org">
								<i class="fa fa-github-alt fa-fw"></i>
								R package on Github
							</a>
						</li>
						<?php if($user->isSuperAdmin()): ?>
						<li>
						    <a href="<?=WEBROOT?>superadmin/cron_log">
								<i class="fa fa-cog fa-fw"></i>
								<?php echo _("cron log"); ?>
							</a>
						</li>

						<li>
						    <a href="<?=WEBROOT?>superadmin/user_management">
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
				    <a href="<?=WEBROOT?>">
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
<div class="survey_header">&nbsp;
</div>	

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
			<li <?=endsWith($_SERVER['SCRIPT_NAME'],'survey/access.php')?' class="active"':''?>>
				<a href="<?=WEBROOT?>admin/survey/<?php echo $study->name; ?>/access">
					<i class="fa-li fa fa-play"></i> <?php echo _("Test study"); ?></a>
			</li>
		

	<li <?=endsWith($_SERVER['SCRIPT_NAME'],'admin/survey/index.php')?' class="active"':''?>>
		<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/index"><i class="fa-li fa fa-cogs"></i> Settings</a>
	</li>
	<li <?=endsWith($_SERVER['SCRIPT_NAME'],'admin/survey/upload_items.php')?' class="active"':''?>>
		<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/upload_items"><i class="fa-li fa fa-table"></i> Import items</a>
	</li>

	<li <?=endsWith($_SERVER['SCRIPT_NAME'],'admin/survey/show_item_table.php')?' class="active"':''?>>
		<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/show_item_table"><i class="fa-li fa fa-th"></i> Item table</a>
	</li>

	<li <?=endsWith($_SERVER['SCRIPT_NAME'],'admin/survey/show_results.php')?' class="active"':''?>>
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
			
				<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_results?format=csv"><i class="fa fa-floppy-o"></i> Download CSV</a>
			</li>
			<li>
				<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_results?format=csv_german"><i class="fa fa-floppy-o"></i> Download German CSV</a>
			</li>
			<li>
				<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_results?format=tsv"><i class="fa fa-floppy-o"></i> Download TSV</a>
			</li>
			<li>
				<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_results?format=xls"><i class="fa fa-floppy-o"></i> Download XLS</a>
			</li>
			<li>
				<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_results?format=xlsx"><i class="fa fa-floppy-o"></i> Download XLSX</a>
			</li>
			<li>
				<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_results?format=json"><i class="fa fa-floppy-o"></i> Download JSON</a>
			</li>
		
	    </ul>
	  </li>

	<li class="nav-header"><i class="fa-li fa fa-bolt"></i> Danger Zone</li>

	<li <?=endsWith($_SERVER['SCRIPT_NAME'],'admin/survey/delete_Survey.php')?' class="active"':''?>>
		<a class="hastooltip" title="Go to deletion dialog, does not delete yet" href="<?=WEBROOT?>admin/survey/<?=$study->name?>/delete_study">
			<i class="fa-li fa fa-trash-o"></i> Delete study</a>
	</li>

	<li <?=endsWith($_SERVER['SCRIPT_NAME'],'admin/survey/delete_results.php')?' class="active"':''?>>
		<a class="hastooltip" title="Go to deletion dialog, does not delete yet" href="<?=WEBROOT?>admin/survey/<?=$study->name?>/delete_results">
			<i class="fa-li fa fa-eraser"></i> Delete results</a>
	
	</li>

	</ul>

	</nav>
<?php elseif(isset($run)): ?>
<div class="run_header">&nbsp;
</div>	
<div class="row">
	<div class="col-lg-12">
		<h1><i class="fa fa-rocket"></i> <?php echo $run->name;?></h1>
	</div>
</div>
<div class="row">
	<nav class="col-lg-2 col-md-2 col-sm-3">
		<ul class="fa-ul  fa-ul-more-padding">
			<li>
				<a href="<?=RUNROOT?><?php echo $run->name; ?>" title="To play as a new user, test the run in a new private/incognito window (right-click in Chrome or Firefox). To play as yourself, simply click the link (remember that the run saves your position and progress)!">
					<i class="fa-li fa fa-play"></i> <?php echo _("Test run"); ?></a>
			</li>
			

			<li <?=endsWith($_SERVER['SCRIPT_NAME'],'run/user_overview.php')?' class="active"':''?> title="Here you can monitor users' progress, send them to a different position and send them manual reminders.">
				<a href="<?=WEBROOT?>admin/run/<?php echo $run->name; ?>/user_overview"><i class="fa-li fa fa-users"></i> <?php echo _("Users"); ?></a>
			</li>
			
			
			<li <?=endsWith($_SERVER['SCRIPT_NAME'],'run/custom_overview.php')?' class="active"':''?> title="Here you can monitor your custom progress indicators, do preliminary data analysis and so on.">
				<a href="<?=WEBROOT?>admin/run/<?php echo $run->name; ?>/overview"><i class="fa-li fa fa-eye"></i> <?php echo _("Overview"); ?></a>
			</li>
			
			<li class="nav-header"><i class="fa-li fa fa-cogs"></i> Configuration</li>
			
			<li <?=endsWith($_SERVER['SCRIPT_NAME'],'run/index.php')?' class="active"':''?>>
				<a href="<?=WEBROOT?>admin/run/<?php echo $run->name; ?>/"><i class="fa-li fa fa-pencil"></i> <?php echo _("Edit run"); ?></a>
			</li>
			
			<li <?=endsWith($_SERVER['SCRIPT_NAME'],'run/settings.php')?' class="active"':''?>>
				<a href="<?=WEBROOT?>admin/run/<?php echo $run->name; ?>/settings"><i class="fa-li fa fa-cogs"></i> <?php echo _("Settings"); ?></a>
			</li>
			
			<li <?=endsWith($_SERVER['SCRIPT_NAME'],'run/upload_files.php')?' class="active"':''?> title="Upload images, videos, sounds and the like.">
				<a href="<?=WEBROOT?>admin/run/<?php echo $run->name; ?>/upload_files"><i class="fa-li fa fa-upload"></i> <?php echo _("Upload files"); ?></a>
			</li>

			<li class="nav-header"><i class="fa-li fa fa-file"></i> logs</li>
			<li <?=endsWith($_SERVER['SCRIPT_NAME'],'run/user_detail.php')?' class="active"':''?> title="Here you'll see users' entire history of participation, i.e. when they left which position etc.">
				<a href="<?=WEBROOT?>admin/run/<?php echo $run->name; ?>/user_detail"><i class="fa-li fa fa-search"></i> <?php echo _("User detail"); ?></a>
			</li>
			
			<li <?=endsWith($_SERVER['SCRIPT_NAME'],'run/random_groups.php')?' class="active"':''?> title="This is simply your log of how users have been randomised.">
				<a href="<?=WEBROOT?>admin/run/<?php echo $run->name; ?>/random_groups"><i class="fa-li fa fa-random"></i> <?php echo _("Random groups"); ?></a>
			</li>
			
	  		<li <?=endsWith($_SERVER['SCRIPT_NAME'],'run/email_log.php')?' class="active"':''?>>
				<a href="<?=WEBROOT?>admin/run/<?php echo $run->name; ?>/email_log" title="The log of every email sent from this run.">
					<i class="fa-li fa fa-envelope"></i> <?php echo _("Emails sent"); ?>
				</a>
			</li>
	  		<li <?=endsWith($_SERVER['SCRIPT_NAME'],'run/cron_log.php')?' class="active"':''?>>
				<a href="<?=WEBROOT?>admin/run/<?php echo $run->name; ?>/cron_log" title="The log of everything that happened without user interaction, i.e. when you click 'Play', like sending email reminders and checking whether pauses are over.">
					<i class="fa-li fa fa-cog"></i> <?php echo _("Cron"); ?>
				</a>
			</li>

			<li class="nav-header"><i class="fa-li fa fa-bolt"></i> Danger Zone</li>
			
			<li <?=endsWith($_SERVER['SCRIPT_NAME'],'admin/run/delete_run.php')?' class="active"':''?>>
				<a class="hastooltip" title="Go to deletion dialog, does not delete yet" href="<?=WEBROOT?>admin/run/<?=$run->name?>/delete_run">
					<i class="fa-li fa fa-trash-o"></i> Delete run</a>
			</li>
			<li <?=endsWith($_SERVER['SCRIPT_NAME'],'admin/run/empty_run.php')?' class="active"':''?>>
				<a class="hastooltip" title="Here you can clean the run of all data." href="<?=WEBROOT?>admin/run/<?=$run->name?>/empty_run">
					<i class="fa-li fa fa-eraser"></i> Empty run</a>
			</li>
			
			<li <?=endsWith($_SERVER['SCRIPT_NAME'],'admin/run/rename_run.php')?' class="active"':''?>>
				<a class="hastooltip" title="Rename your run, but be careful, this also changes the link." href="<?=WEBROOT?>admin/run/<?=$run->name?>/rename_run">
					<i class="fa-li fa fa-unlock"></i> Rename run</a>
			</li>

		</ul>
	</nav>
<?php else: ?>
<div class="row">
<?php endif; ?>

<?php if(!isset($study) AND !isset($run)): ?>
	<div class="col-md-12 main_body">
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