<?php
require_once 'define_root.php';
require_once INCLUDE_ROOT."config/config.php";

require_once INCLUDE_ROOT."view_header.php";
require_once INCLUDE_ROOT."public_nav.php";

if(userIsLoggedIn()) {
  header("Location: index.php");
  die();
}
if(!isset($_SESSION['userMail']))
  header("Location: index.php");
?>

<p><?php echo _("Successfully registered!"); ?></p>
<p><a href="<?=WEBROOT?>login.php"><?php echo _("Login now"); ?></a></p>

	
<?php
require_once INCLUDE_ROOT."view_footer.php";
