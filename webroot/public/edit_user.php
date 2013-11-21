<?php
/* todo: allow changing email address
todo: email address verification
todo: my access code has been compromised, reset? possible problems with external data, maybe they should get their own tokens...
*/
require_once '../../define_root.php';
require_once INCLUDE_ROOT."Model/Site.php";
if(!$user->loggedIn()) {
	alert('You need to be logged in to go here.','alert-info');
	redirect_to("index");
}


if(!empty($_POST)) {
	if( 
		$user->changePassword($_POST['password'],$_POST['new_password'])
	)
	{
		alert('<strong>Success!</strong> Your password was changed!','alert-success');
		redirect_to('index');
	}
	else {
		alert(implode($user->errors),'alert-danger');
	}
}
require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/public_nav.php";

?>
<div class="col-lg-4 col-md-6 col-sm-7 col-md-offset-1 well">
<h2>Edit settings</h2>
<form class="form-horizontal form-horizontal-small-left" id="edit_user" name="edit_user" method="post" action="edit_user.php">
	<div class="form-group small-left">
		<label class="control-label" for="password">
			<?php echo _("Old password"); ?>
		</label>
		<div class="controls">
			<input  class="form-control" required type="password" placeholder="Enter your old password" name="password" id="password">
		</div>
	</div>
	<div class="form-group small-left">
		<label class="control-label" for="password">
			<?php echo _("New password"); ?>
		</label>
		<div class="controls">
			<input  class="form-control" required type="password" placeholder="Please choose a secure phrase" name="new_password" id="password">
		</div>
	</div>
	<div class="form-group small-left">
		<div class="controls">
			<button required type="submit" class="btn btn-default"><i class="fa fa-pencil fa-fw"></i> <?php echo _("Save"); ?></button>
		</div>
	</div>
</form>
</div>


<?php
require_once INCLUDE_ROOT . "View/footer.php";
