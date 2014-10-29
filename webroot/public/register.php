<?php
/* @var $user User */
/* @var $site Site */

//fixme: cookie problems lead to fatal error with missing user code
if($user->loggedIn()) {
	alert('You were already logged in. Please logout before you can register.', 'alert-info');
	redirect_to("index");
}

if($site->request->str('email')) {
	if($user->register($site->request->str('email'), $site->request->str('password'))) {
		alert('<strong>Success!</strong> You were registered and logged in!','alert-success');
		redirect_to('index');
	} else {
		alert(implode($user->errors),'alert-danger');
	}
}
?>
<?php Template::load('header_nav'); ?>
<div class="row">
	<div class="col-lg-4 col-lg-offset-1 col-sm-5 col-sm-offset-1 col-xs-12 well">
		<h2>Sign up</h2>
		<p class="lead">It's free. We don't spam.</a>
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
					<button class="btn btn-default btn-success" type="submit"><i class="fa fa-pencil fa-fw"></i> <?php echo _("sign up"); ?></button>
				</div>
			</div>
		</form>
	</div>
</div>
<?php Template::load('footer');
