<?php
require_once 'define_root.php';
require_once 'Model/Site.php';

//fixme: cookie problems lead to fatal error with missing user code
if($user->loggedIn()) {
	alert('You were already logged in. Please logout before you can register.','alert-info');
	redirect_to("index");
}

if(!empty($_POST)) {
	if( 
		$user->register($_POST['email'],$_POST['password'])
	)
	{
		alert('<strong>Success!</strong> You were registered and logged in!','success');
		redirect_to('index');
	}
	else {
		alert(implode($user->errors),'alert-error');
	}
}

require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/public_nav.php";
?>
<div class="span8">
<h2>Registration</h2>
<form class="form-horizontal" id="register" name="register" method="post" action="register.php">
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
			<input required class="btn btn-success" type="submit" value="<?php echo _("Register"); ?>">
		</div>
	</div>
</form>
</div>
<?php
require_once INCLUDE_ROOT . "View/footer.php";
