<?php Template::load('header_nav'); ?>
<div class="row">
	<div class="col-lg-4 col-lg-offset-1 col-sm-5 col-sm-offset-1 col-xs-12 well">
	<h2>Reset password</h2>
		<form class="" id="login" name="login" method="post" action="<?=WEBROOT?>public/reset_password">
		<div class="form-group small-left">
			<label class="control-label sr-only" for="new_password">
				<?php echo _("New password"); ?>
	  		  <input required type="hidden" name="email" id="email" value="<?=htmlspecialchars($_GET['email']);?>">
	  		  <input required type="hidden" name="reset_token" id="reset_token" value="<?=htmlspecialchars($_GET['reset_token']);?>">
				
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
<?php Template::load('footer'); ?>