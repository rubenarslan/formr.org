<?php
require_once 'define_root.php';
require_once INCLUDE_ROOT."Model/Site.php";

if($user->loggedIn()) {
  header("Location: index.php");
  exit;
}

if(!empty($_POST)) {
	if( 
		$user->login($_POST['email'],$_POST['password'])
	)
	{
		alert('<strong>Success!</strong> You were logged in!','success');
		redirect_to('index.php');
	}
	else {
		alert(implode($user->errors),'alert-error');
	}
}

require_once INCLUDE_ROOT."view_header.php";
require_once INCLUDE_ROOT."public_nav.php";


require_once INCLUDE_ROOT."view_header.php";
require_once INCLUDE_ROOT."public_nav.php";
?>
<div class="span8">
<h2>Login</h2>
<form class="form-horizontal" id="login" name="login" method="post" action="login.php">
	<div class="control-group small-left">
		<label class="control-label" for="email">
			<?php echo _("Email"); ?>
		</label>
		<div class="controls">
			<input required type="email" placeholder="email@example.com" name="email" id="email">
		</div>
	</div>
	<div class="control-group small-left">
		<label class="control-label" for="password">
			<?php echo _("Password"); ?>
		</label>
		<div class="controls">
			<input required type="password" placeholder="Please choose a secure phrase" name="password" id="password">
		</div>
	</div>
	<div class="control-group small-left">
		<div class="controls">
			<input required type="submit" value="<?php echo _("login"); ?>">
		</div>
	</div>
</form>
</div>
<?php
require_once INCLUDE_ROOT."view_footer.php";