<h2><em>formr</em> admin<?php if(isset($run)): ?>
/run/<?php echo $run->name;?>

<?php elseif(isset($study)): ?>
/survey/<?php echo $study->name;?>

<?php endif;?>
</h2>

<nav class="main_admin_nav">
	<ul class="nav nav-tabs">
	    <li <?php
		echo endsWith($_SERVER['PHP_SELF'],'admin/index.php')?' class="active"':''?>><a href="<?=WEBROOT?>admin/">
		<i class="fa fa-eye-slash fa-fw"></i>
		admin control panel
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
				
			</ul>
		</li>

		<li <?=endsWith($_SERVER['PHP_SELF'],'webroot/index.php')?' class="active"':''?>>
			<a href="<?=WEBROOT?>"><i class="fa fa-eye fa-fw"></i> public area</a>
		</li>

		<li><a href="<?=WEBROOT?>public/logout"><i class="fa fa-sign-out fa-fw"></i> log out</a></li>
	</ul>

</nav>

<?php if(isset($study)): ?>

<?php
$resultCount = $study->getResultCount();
?>
<h3><i class="fa fa-pencil-square"></i> <?php echo $study->name;?> <small><?= ($resultCount['begun']+$resultCount['finished'])?> results</small></h3>
	
<nav class="col-md-no-left-pad col-md-1">
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
	<h3><i class="fa fa-rocket"></i> <?php echo $run->name;?></h3>
	
<nav class="col-md-no-left-pad col-md-1">
	<ul class="fa-ul  fa-ul-more-padding">

	<li <?=endsWith($_SERVER['PHP_SELF'],'run/index.php')?' class="active"':''?>>
		<a href="<?=WEBROOT?>admin/run/<?php echo $run->name; ?>/"><i class="fa-li fa fa-pencil"></i> <?php echo _("Edit Run"); ?></a>
	</li>

	<li <?=endsWith($_SERVER['PHP_SELF'],'run/user_overview.php')?' class="active"':''?>>
		<a href="<?=WEBROOT?>admin/run/<?php echo $run->name; ?>/user_overview"><i class="fa-li fa fa-users"></i> <?php echo _("User Overview"); ?></a>
	</li>
	<li <?=endsWith($_SERVER['PHP_SELF'],'run/user_detail.php')?' class="active"':''?>>
		<a href="<?=WEBROOT?>admin/run/<?php echo $run->name; ?>/user_detail"><i class="fa-li fa fa-search"></i> <?php echo _("User Detail"); ?></a>
	</li>
</ul>


</nav>
<?php endif; ?>

<div class="main_body col-md-10">
<?php 
$alerts = $site->renderAlerts();
if(!empty($alerts)):
	echo '<div class="col-md-6 all-alerts">';
	echo $alerts;
	echo '</div>';
endif;
?>