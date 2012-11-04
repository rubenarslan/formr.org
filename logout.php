<?php
require_once "config/config.php";
if(userIsLoggedIn()) {
  global $currentUser;
  $currentUser->logout();
}
header("Location: index.php");
die();
?>
