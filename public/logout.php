<?php
/* require_once $_SERVER['DOCUMENT_ROOT']."/tmp/config/config.php"; */
require_once "config/config.php";
if(userIsLoggedIn()) {
  global $currentUser;
  $currentUser->logout();
}
header("Location: index.php");
die();
?>
