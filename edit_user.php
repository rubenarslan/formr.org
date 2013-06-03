<?php
require_once 'define_root.php';
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
		alert(implode($user->errors),'alert-error');
	}
}
require_once INCLUDE_ROOT."view_header.php";
require_once INCLUDE_ROOT."public_nav.php";

?>
<h2>Edit settings</h2>
<form class="form-horizontal" id="edit_user" name="edit_user" method="post" action="edit_user.php">
	<div class="control-group small-left">
		<label class="control-label" for="password">
			<?php echo _("Old password"); ?>
		</label>
		<div class="controls">
			<input required type="password" placeholder="Please choose a secure phrase" name="password" id="password">
		</div>
	</div>
	<div class="control-group small-left">
		<label class="control-label" for="password">
			<?php echo _("New password"); ?>
		</label>
		<div class="controls">
			<input required type="password" placeholder="Please choose a secure phrase" name="new_password" id="password">
		</div>
	</div>
	<div class="control-group small-left">
		<div class="controls">
			<input required type="submit" value="<?php echo _("Save"); ?>">
		</div>
	</div>
</form>
</div>


<?php
require_once INCLUDE_ROOT."view_footer.php";
