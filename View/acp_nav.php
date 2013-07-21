<h1>Admin control panel</h1>

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
			<a href="<?=WEBROOT?>index"><?php echo _("public area"); ?></a>
		</li>

		<li><a href="<?=WEBROOT?>logout"><?php echo _("log out"); ?></a></li>
	</ul>

</nav>

<?php 
echo $site->renderAlerts();
?>