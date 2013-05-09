<?php
require_once 'define_root.php';
require_once INCLUDE_ROOT."Model/Site.php";
if($user->logged_in) {
	$user->logout();
	alert('<strong>Logged out:</strong> You have been logged out.','alert-info');
}
redirect_to("index.php");
