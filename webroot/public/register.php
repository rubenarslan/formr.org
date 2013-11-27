<?php
require_once '../../define_root.php';
require_once INCLUDE_ROOT.'Model/Site.php';

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
		alert('<strong>Success!</strong> You were registered and logged in!','alert-success');
		redirect_to('index');
	}
	else {
		alert(implode($user->errors),'alert-danger');
	}
}

require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/public_nav.php";
?>
<div class="row">
	<div class="col-lg-4 col-lg-offset-1 col-sm-5 col-sm-offset-1 col-xs-12 well">
		<h2>Registration</h2>
		<form class="" id="register" name="register" method="post" action="<?=WEBROOT?>public/register">
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
				<label class="control-label sr-only" for="password">
					<?php echo _("Password"); ?>
				</label>
				<div class="controls">
					<div class="input-group">
					  <span class="input-group-addon"><i class="fa fa-key fa-fw"></i></span>
			  		  <input class="form-control" required type="password" placeholder="Please choose a secure phrase" name="password" id="password">
					</div>
				</div>
			</div>
			<div class="form-group small-left">
				<div class="controls">
					<button class="btn btn-default btn-success" type="submit"><i class="fa fa-pencil fa-fw"></i> <?php echo _("Register"); ?></button>
				</div>
			</div>
		</form>
	</div>
</div>
<?php
require_once INCLUDE_ROOT . "View/footer.php";
