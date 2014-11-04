<?php
if((!isset($_GET['verification_token']) OR !isset($_GET['email']) ) AND !isset($_POST['email'])):
	alert("You need to follow the link you received in your verification mail.");
	redirect_to("public/login");
else:
	$user->verify_email($_GET['email'], $_GET['verification_token']);
	redirect_to("public/login");
endif;
