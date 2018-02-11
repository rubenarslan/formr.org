<?php
Template::load('public/header');
?>

<section id="fmr-hero" class="js-fullheight full" data-next="yes">
	<div class="fmr-overlay"></div>
	<div class="container">
		<div class="fmr-intro js-fullheight">
			<div class="row">
				<div class="fmr-intro-text">
					<div class="col-md-12">
						<div class="login-form large well">
							<h2>Edit Account [<?php echo $user->email; ?>]</h2>
							<?php Template::load('public/alerts'); ?>

							<form id="edit_user" name="edit_user" method="post" action="">
								<h4 class="lead">Changes are effective immediately</h4>
								<div class="form-group label-floating">
									<label class="control-label" for="email"><i class="fa fa-envelope-o fa-fw"></i> New Email (Leave empty to keep current email)</label>
									<input class="form-control" type="email" id="email" name="new_email" autocomplete="off">
								</div>
								<div class="form-group label-floating">
									<label class="control-label" for="pass2"><i class="fa fa-key fa-fw"></i> Enter New Password (Choose a secure phrase)</label>
									<input class="form-control" type="password" id="pass2" name="new_password" autocomplete="off">
								</div>
								<div class="form-group label-floating">
									<label class="control-label" for="pass3"><i class="fa fa-key fa-fw"></i> Confirm New Password</label>
									<input class="form-control" type="password" id="pass3" name="new_password_c" autocomplete="off">
								</div>
								<p>&nbsp;</p>
								<h4>Enter Old Password to effect changes</h4>
								<div class="form-group label-floating">
									<label class="control-label" for="pass"><i class="fa fa-key fa-fw"></i> Enter Old Password (Required to make changes)</label>
									<input class="form-control" type="password" id="pass" name="password" autocomplete="off">
								</div>
								<button class="btn btn-sup btn-material-pink btn-raised"><i class="fa fa-save"></i> Save</button>
							</form>
						</div>
					</div>
					<div class="clearfix"></div>
				</div>

				<p>&nbsp;</p>
			</div>
		</div>
	</div>
</section>

<?php Template::load('public/footer'); ?>
