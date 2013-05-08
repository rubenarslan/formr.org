<?php
require_once 'define_root.php';
require_once INCLUDE_ROOT."config/config.php";
if(userIsLoggedIn()) {
  global $currentUser;
  $currentUser->logout();
}
header("Location: index.php");
die();
?>
