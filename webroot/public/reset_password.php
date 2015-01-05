<?php
require_once '../../define_root.php';
require_once INCLUDE_ROOT."Model/Site.php";

if($user->loggedIn()) {
	redirect_to("index");
}

if((!isset($_GET['reset_token']) OR !isset($_GET['email']) ) AND !isset($_POST['email'])):
	alert("You need to follow the link you received in your password reset mail");
	redirect_to("public/forgot_password");
endif;

if(!empty($_POST) AND isset($_POST['email'])  AND isset($_POST['new_password'])  AND isset($_POST['reset_token'])) {
	$user->reset_password($_POST['email'],$_POST['reset_token'],$_POST['new_password']);
}

$user_reset_email = isset($_GET['email']) ? $_GET['email'] : '';
$user_reset_token = isset($_GET['reset_token']) ? $_GET['reset_token'] : '';

require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/public_nav.php";
?>
<div class="row">
	<div class="col-lg-4 col-lg-offset-1 col-sm-5 col-sm-offset-1 col-xs-12 well">
	<h2>Reset password</h2>
		<form class="" id="login" name="login" method="post" action="<?=WEBROOT?>public/reset_password">
		<div class="form-group small-left">
			<label class="control-label sr-only" for="new_password">
				<?php echo _("New password"); ?>
	  		  <input required type="hidden" name="email" id="email" value="<?=htmlspecialchars($user_reset_email);?>">
	  		  <input required type="hidden" name="reset_token" id="reset_token" value="<?=htmlspecialchars($user_reset_token);?>">
				
			</label>
			<div class="controls">
				<div class="input-group">
				  <span class="input-group-addon"><i class="fa fa-key fa-fw"></i></span>
				  <input class="form-control"  required type="password" placeholder="Your new password" name="new_password" id="new_password">
				</div>
			</div>
		</div>
			<div class="form-group small-left">
				<div class="controls">
					<input type="submit" value="<?php echo _("Change my password."); ?>"  class="btn btn-default btn-info">
				</div>
			</div>
		</form>
	</div>
</div>
<?php
require_once INCLUDE_ROOT . "View/footer.php";
