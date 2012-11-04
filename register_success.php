<?php
require_once "config/config.php";

if(userIsLoggedIn()) {
  header("Location: index.php");
  die();
}
if(!isset($_SESSION['userMail']))
  header("Location: index.php");
?>
<?php
include("pre_content.php");
?>	

<p>Registrierung erfolgreich!</p>
<p><a href="login.php"><?php echo _("Einloggen"); ?></a></p>

	
<?php
include("post_content.php");
?>	