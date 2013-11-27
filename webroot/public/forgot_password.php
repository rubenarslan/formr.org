<?php
require_once '../../define_root.php';
require_once INCLUDE_ROOT."Model/Site.php";

if($user->loggedIn()) {
	redirect_to("index");
}

if(!empty($_POST) AND isset($_POST['email'])) {
	$user->forgot_password($_POST['email']);
}

require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/public_nav.php";
?>
<div class="row">
	<div class="col-lg-4 col-lg-offset-1 col-sm-5 col-sm-offset-1 col-xs-12 well">
	<h2>Forgot password</h2>
		<form class="" id="login" name="login" method="post" action="<?=WEBROOT?>public/forgot_password">
			<div class="form-group small-left">
				<label class="control-label sr-only" for="email">
					<?php echo _("Email"); ?>
				</label>
				<div class="controls">
					<div class="input-group">
					  <span class="input-group-addon"><i class="fa fa-envelope-o fa-fw"></i></span>
					  <input class="form-control"  required type="email" placeholder="Your email address" name="email" id="email">
					</div>
				</div>
			</div>
			<div class="form-group small-left">
				<div class="controls">
					<input type="submit" value="<?php echo _("Send me a link to choose a new password"); ?>"  class="btn btn-default btn-info">
				</div>
			</div>
		</form>
	</div>
</div>
<?php
require_once INCLUDE_ROOT . "View/footer.php";
