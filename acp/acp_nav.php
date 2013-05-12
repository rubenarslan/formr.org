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
		<li <?=endsWith($_SERVER['PHP_SELF'],'index.php')?' class="active"':''?>>
			<a href="<?=WEBROOT?>index"><?php echo _("public area"); ?></a>
		</li>

		<li><a href="<?=WEBROOT?>logout"><?php echo _("log out"); ?></a></li>
	</ul>

</nav>

<?php 
echo $site->renderAlerts();
?>