<h1><em>formr</em> admin area</h1>

<nav>
	<ul class="nav nav-tabs">
	    <li <?php
		echo endsWith($_SERVER['PHP_SELF'],'admin/index.php')?' class="active"':''?>><a href="<?=WEBROOT?>admin/"><?php echo _("admin control panel"); ?></a></li>   
	
		
  		<li class="dropdown">
  			<a class="dropdown-toggle"
  			data-toggle="dropdown"
  			href="#">
  				surveys
  				<b class="caret"></b>
  			</a>
		  <ul class="dropdown-menu">
	  		<li <?=endsWith($_SERVER['PHP_SELF'],'add_study.php')?' class="active"':''?>>
	  			<a href="<?=WEBROOT?>admin/add_study"><?php echo _("create new survey"); ?></a>
	  		</li>
			<li class="divider"></li>
			  		
		<?php
		$studies = $user->getStudies();
		if($studies) {
		  foreach($studies as $menu_study) {
		    echo "<li>
				<a href='".WEBROOT."admin/survey/".$menu_study['name']."/'>".$menu_study['name']."</a>
			</li>";
		  }
		}
		?>
		</ul>
	</li>
	<li class="dropdown">
		<a class="dropdown-toggle"
		data-toggle="dropdown"
		href="#">
			runs
			<b class="caret"></b>
		</a>
	  <ul class="dropdown-menu">
  		<li <?=endsWith($_SERVER['PHP_SELF'],'add_run.php')?' class="active"':''?>>
  			<a href="<?=WEBROOT?>admin/add_run"><?php echo _("create new run"); ?></a>
		<li class="divider"></li>
		  		
		<?php
		$runs = $user->getRuns();
		if($runs) {
		  foreach($runs as $menu_run) {
		    echo '<li>
				<a href="'.WEBROOT.'admin/run/'.$menu_run['name'].'/">'.$menu_run['name'].'</a>
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
			mail
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
		<li class="divider"></li>
		<?php
		$accs = $user->getEmailAccounts();
		if($accs) {
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
				more
				<b class="caret"></b>
			</a>
			<ul class="dropdown-menu">
				<li <?=endsWith($_SERVER['PHP_SELF'],'user_overview.php')?' class="active"':''?>>
					<a href="<?=WEBROOT?>admin/user_overview"><?php echo _("user overview"); ?></a>
				</li>
				<li <?=endsWith($_SERVER['PHP_SELF'],'user_detail.php')?' class="active"':''?>>
					<a href="<?=WEBROOT?>admin/user_detail"><?php echo _("user detail"); ?></a>
				</li>
				<li <?=endsWith($_SERVER['PHP_SELF'],'cron_log.php')?' class="active"':''?>>
					<a href="<?=WEBROOT?>admin/cron_log"><?php echo _("cron log"); ?></a>
				</li>
				
			</ul>
		</li>

		<li <?=endsWith($_SERVER['PHP_SELF'],'webroot/index.php')?' class="active"':''?>>
			<a href="<?=WEBROOT?>"><?php echo _("public area"); ?></a>
		</li>

		<li><a href="<?=WEBROOT?>public/logout"><?php echo _("log out"); ?></a></li>
	</ul>

</nav>

<?php if(isset($study)): ?>

<?php
$resultCount = $study->getResultCount();
?>
<h2><?php echo $study->name;?> <small><?= ($resultCount['begun']+$resultCount['finished'])?> results</small></h2>
	
<nav class="span2">
	<ul class="nav nav-pills nav-stacked">
		<li <?=endsWith($_SERVER['PHP_SELF'],'survey/access.php')?' class="active"':''?>>
			<a href="<?=WEBROOT?>admin/survey/<?php echo $study->name; ?>/access">
				<i class="icon-caret-right"></i> <?php echo _("Test study"); ?></a>
		</li>
		

<li <?=endsWith($_SERVER['PHP_SELF'],'admin/survey/index.php')?' class="active"':''?>>
	<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/index"><i class="icon-caret-right"></i> Global settings</a>
</li>
<li <?=endsWith($_SERVER['PHP_SELF'],'admin/survey/upload_items.php')?' class="active"':''?>>
	<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/upload_items"><i class="icon-caret-right"></i> Import item table</a>
</li>

<li <?=endsWith($_SERVER['PHP_SELF'],'admin/survey/show_item_table.php')?' class="active"':''?>>
	<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/show_item_table"><i class="icon-caret-right"></i> View item table</a>
</li>

<li <?=endsWith($_SERVER['PHP_SELF'],'admin/survey/show_results.php')?' class="active"':''?>>
	<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/show_results"><i class="icon-caret-right"></i> Show results</a>
</li>

<li class="dropdown">
    <a class="dropdown-toggle"
       data-toggle="dropdown"
       href="#">
        <i class="icon-caret-right"></i> Export results
        <b class="caret"></b>
      </a>
    <ul class="dropdown-menu">
		<li>
			
			<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_csv"><i class="icon-caret-down"></i> Download CSV</a>
		</li>
		<li>
			<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_csv_german"><i class="icon-caret-down"></i> Download German CSV</a>
		</li>
		<li>
			<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_tsv"><i class="icon-caret-down"></i> Download TSV</a>
		</li>
		<li>
			<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_xls"><i class="icon-caret-down"></i> Download XLS</a>
		</li>
		<li>
			<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_xlsx"><i class="icon-caret-down"></i> Download XLSX</a>
		</li>
		
    </ul>
  </li>

<li class="nav-header">complex studies</li>

<li <?=endsWith($_SERVER['PHP_SELF'],'admin/survey/edit_substitutions.php')?' class="active"':''?>>
	<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/edit_substitutions"><i class="icon-caret-right"></i> Edit substitutions</a>
</li>

<li class="nav-header">Danger Zone</li>

<li <?=endsWith($_SERVER['PHP_SELF'],'admin/survey/delete_study.php')?' class="active"':''?>>
	<a class="hastooltip" title="Go to deletion dialog, does not delete yet" href="<?=WEBROOT?>admin/survey/<?=$study->name?>/delete_study"><i class="icon-caret-right"></i> Delete study</a>
</li>

<li <?=endsWith($_SERVER['PHP_SELF'],'admin/survey/delete_results.php')?' class="active"':''?>>
	<a class="hastooltip" title="Go to deletion dialog, does not delete yet" href="<?=WEBROOT?>admin/survey/<?=$study->name?>/delete_results"><i class="icon-caret-right"></i> Delete <?= ($resultCount['begun']+$resultCount['finished'])?> results</a>
	
</li>

</ul>

</nav>
<?php endif; ?>


<?php if(isset($run)): ?>

<h2><?php echo $run->name;?></h2>

<ul class="nav nav-tabs">
	<li <?=endsWith($_SERVER['PHP_SELF'],'run/index.php')?' class="active"':''?>>
		<a href="<?=WEBROOT?>admin/run/<?php echo $run->name; ?>/"><?php echo _("Run"); ?></a>
	</li>

	<li <?=endsWith($_SERVER['PHP_SELF'],'run/user_overview.php')?' class="active"':''?>>
		<a href="<?=WEBROOT?>admin/run/<?php echo $run->name; ?>/user_overview"><?php echo _("Overview"); ?></a>
	</li>
	<li <?=endsWith($_SERVER['PHP_SELF'],'run/user_detail.php')?' class="active"':''?>>
		<a href="<?=WEBROOT?>admin/run/<?php echo $run->name; ?>/user_detail"><?php echo _("Detail"); ?></a>
	</li>
</ul>


</nav>
<?php endif; ?>

<?php 
$alerts = $site->renderAlerts();
if(!empty($alerts)):
	echo '<div class="span8 all-alerts">';
	echo $alerts;
	echo '</div>';
endif;
?>