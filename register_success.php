<?php
require_once "includes/define_root.php";
require_once INCLUDE_ROOT."config/config.php";

require_once INCLUDE_ROOT."view_header.php";


echo '<ul class="nav nav-tabs">';

if(userIsAdmin())
{
   ?>
    <li><a href="<?=WEBROOT?>acp/acp.php"><?php echo _("Admin control panel"); ?></a></li>   
   <?php
}

if(userIsLoggedIn()) {  
	?>
	<li><a href="<?=WEBROOT?>logout.php"><?php echo _("Ausloggen"); ?></a></li>
	<li><a href="<?=WEBROOT?>edit_user.php"><?php echo _("Einstellungen ändern"); ?></a></li>
<?php
} else {
?>
     <li><a href="<?=WEBROOT?>login.php"><?php echo _("Login"); ?></a></li>
     <li><a href="<?=WEBROOT?>register.php"><?php echo _("Registrieren") ?></a></li>
<?php
}

echo "</ul>";

if(userIsLoggedIn()) {
  header("Location: index.php");
  die();
}
if(!isset($_SESSION['userMail']))
  header("Location: index.php");
?>

<p><?php echo _("Registrierung erfolgreich!"); ?></p>
<p><a href="<?=WEBROOT?>login.php"><?php echo _("Einloggen"); ?></a></p>

	
<?php
require_once INCLUDE_ROOT."view_footer.php";
