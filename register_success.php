<?php
/* require_once $_SERVER['DOCUMENT_ROOT']."/tmp/config/config.php"; */
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


<p><a href="index.php"><?php echo $lang['INDEX']; ?></a></p>
<p><a href="login.php"><?php echo $lang['LOGIN']; ?></a></p>

	
<?php
include("post_content.php");
?>	