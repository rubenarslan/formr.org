<h1>Admin control panel</h1>

<nav>
	<ul class="nav nav-tabs">
	    <li <?php
		echo endsWith($_SERVER['PHP_SELF'],'acp.php')?' class="active"':''?>><a href="<?=WEBROOT?>acp/acp"><?php echo _("admin control panel"); ?></a></li>   
	
		<li <?=endsWith($_SERVER['PHP_SELF'],'add_study.php')?' class="active"':''?>>
			<a href="<?=WEBROOT?>acp/add_study"><?php echo _("create study"); ?></a>
		</li>
		<li <?=endsWith($_SERVER['PHP_SELF'],'add_run.php')?' class="active"':''?>>
			<a href="<?=WEBROOT?>acp/add_run"><?php echo _("create run"); ?></a>
		</li>
		
		<li class="dropdown">
			<a class="dropdown-toggle"
			data-toggle="dropdown"
			href="#">
				more
				<b class="caret"></b>
			</a>
			<ul class="dropdown-menu">
				<li <?=endsWith($_SERVER['PHP_SELF'],'list_email_accounts.php')?' class="active"':''?>>
					<a href="<?=WEBROOT?>acp/list_email_accounts"><?php echo _("list accounts"); ?></a>
				</li>
				<li <?=endsWith($_SERVER['PHP_SELF'],'user_overview.php')?' class="active"':''?>>
					<a href="<?=WEBROOT?>acp/user_overview"><?php echo _("user overview"); ?></a>
				</li>
				<li <?=endsWith($_SERVER['PHP_SELF'],'cron_log.php')?' class="active"':''?>>
					<a href="<?=WEBROOT?>acp/cron_log"><?php echo _("cron log"); ?></a>
				</li>
				<li <?=endsWith($_SERVER['PHP_SELF'],'email_log.php')?' class="active"':''?>>
					<a href="<?=WEBROOT?>acp/email_log"><?php echo _("email log"); ?></a>
				</li>
				<li <?=endsWith($_SERVER['PHP_SELF'],'index.php')?' class="active"':''?>>
					<a href="<?=WEBROOT?>index"><?php echo _("public area"); ?></a>
				</li>

			</ul>
			</li>

		<li><a href="<?=WEBROOT?>logout"><?php echo _("log out"); ?></a></li>
	</ul>

</nav>

<?php 
echo $site->renderAlerts();
?>