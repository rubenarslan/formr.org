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
						<div class="login-form mdl-card mdl-shadow--2dp">
							<h2>Edit Account</h2>
							<?php Template::load('public/alerts'); ?>

							<form id="edit_user" name="edit_user" method="post" action="">
								<div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
									<input class="mdl-textfield__input" type="password" id="pass" name="password">
									<label class="mdl-textfield__label" for="pass"><i class="fa fa-key fa-fw"></i> Enter Old Password (Required to make changes)</label>
								</div>
								<h4 class="lead">Changes are effective immediately</h4>
								<div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label" style="width: 100%;">
									<input class="mdl-textfield__input" type="email" id="email" name="new_email">
									<label class="mdl-textfield__label" for="email"><i class="fa fa-envelope-o fa-fw"></i> New Email (Leave empty to keep current email)</label>
								</div>
								<div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
									<input class="mdl-textfield__input" type="password" id="pass2" name="new_password">
									<label class="mdl-textfield__label" for="pass2"><i class="fa fa-key fa-fw"></i> Enter New Password (Choose a secure phrase)</label>
								</div>
								<button class="btn-primary mdl-button mdl-js-button mdl-button--raised mdl-js-ripple-effect"><i class="fa fa-save"></i> Save</button>
							</form>
						</div>
					</div>
				</div>

				<div class="clearfix"></div>
				<p>&nbsp;</p>
			</div>
		</div>
	</div>
</section>

<?php Template::load('public/footer'); ?>
