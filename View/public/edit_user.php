<?php Template::load('header_nav'); ?>

<div class="col-lg-5 col-md-6 col-sm-7 col-md-offset-1 well">
<h2>Edit settings</h2>
<form class="form-horizontal form-horizontal-small-left" id="edit_user" name="edit_user" method="post" action="">
	<div class="form-group small-left">
		<label class="control-label" for="password">
			<?php echo _("Old password<br><small>Required to make changes</small>"); ?>
		</label>
		<div class="controls">
			<input  class="form-control" required type="password" placeholder="Enter your old password" name="password" id="password">
		</div>
	</div>
	
	<div class="form-group small-left">
		<label class="control-label" for="password">
			<?php echo _("New email address"); ?>
		</label>
		<div class="controls">
				<div class="input-group">
				  <span class="input-group-addon"><i class="fa fa-envelope-o fa-fw"></i></span>
				  <input class="form-control"  type="email" placeholder="Leave empty to keep" name="new_email" id="new_email">
				</div>
		</div>
	</div>

	<div class="form-group small-left">
		<label class="control-label" for="password">
			<?php echo _("New password"); ?>
		</label>
		<div class="controls">
			<input  class="form-control" type="password" placeholder="Please choose a secure phrase" name="new_password" id="password">
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
Template::load('footer');
