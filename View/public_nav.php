<nav class="main_public_nav">
<ul class="nav nav-tabs">
    <li <?=endsWith($_SERVER['PHP_SELF'],'index.php')?' class="active"':''?>><a href="<?=WEBROOT?>">
		<i class="fa fa-circle fa-fw"></i>
		<?php echo _("home"); ?>
	</a></li>
	
    <li <?=endsWith($_SERVER['PHP_SELF'],'documentation.php')?' class="active"':''?>><a href="<?=WEBROOT?>public/documentation">
		<i class="fa fa-file fa-fw"></i>
		<?php echo _("documentation"); ?>
	</a></li>

    <li <?=endsWith($_SERVER['PHP_SELF'],'studies.php')?' class="active"':''?>><a href="<?=WEBROOT?>public/studies">
		<i class="fa fa-pencil-square fa-fw"></i>
		<?php echo _("studies"); ?>
	</a></li>


<?php

if($user->isAdmin()):
   ?>
    <li><a href="<?=WEBROOT?>admin/">
		<i class="fa fa-eye-slash fa-fw"></i>
		<?php echo _("admin control panel"); ?>
	</a></li>   
   <?php
endif;
if($user->loggedIn()):
?>
	<li <?=endsWith($_SERVER['PHP_SELF'],'edit_user.php')?' class="active"':''?>><a href="<?=WEBROOT?>public/edit_user">
		<i class="fa fa-cogs fa-fw"></i>
		<?php echo _("settings"); ?></a></li>
	<li><a href="<?=WEBROOT?>public/logout">
		<i class="fa fa-cogs fa-sign-out"></i>
		<?php echo _("logout"); ?></a></li>
<?php
else:
?>
	<li <?=endsWith($_SERVER['PHP_SELF'],'login.php')?' class="active"':''?>><a href="<?=WEBROOT?>public/login">
		<i class="fa fa-sign-in fa-fw"></i>
		<?php echo _("login"); ?>
	</a></li>
	<li <?=endsWith($_SERVER['PHP_SELF'],'register.php')?' class="active"':''?>><a href="<?=WEBROOT?>public/register">
		<i class="fa fa-pencil fa-fw"></i>
		<?php echo _("sign up") ?>
	</a></li>
<?php
endif;
?>
</ul>
</nav>

<div class="main_body container">
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