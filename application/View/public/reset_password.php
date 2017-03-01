<?php Template::load('header'); ?>

<section id="fmr-header">
	<div class="container">
		<?php Template::load('public_nav'); ?>
	</div>
</section>

<section id="fmr-hero" class="js-fullheight full" data-next="yes">
	<div class="fmr-overlay"></div>
	<div class="container">
		<div class="fmr-intro js-fullheight">
			<div class="row">
				<div class="col-md-6 col-md-offset-3">
					<div class="fmr-intro-text">
						<div class="col-md-12">
							<div class="login-form below-header mdl-card mdl-shadow--2dp">
								<h2>Reset Password</h2>
								<?= Template::load('public/alerts') ?>
								
                                            <form class="" id="login" name="login" method="post" action="<?php echo site_url('reset_password');?>">
                                                <div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
                                                    <input required type="hidden" name="email" id="email" value="<?= htmlspecialchars($reset_data_email); ?>">
                                                    <input required type="hidden" name="reset_token" id="reset_token" value="<?= htmlspecialchars($reset_data_token); ?>">
                                                    <input class="mdl-textfield__input" type="password" name="new_password" id="new_password">
                                                    <label class="mdl-textfield__label" for="new_password"><i class="fa fa-key fa-fw"></i> Enter New Password (Choose a secure phrase)</label>
                                                </div>
                                                <button class="btn-primary mdl-button mdl-js-button mdl-button--raised mdl-js-ripple-effect"><i class="fa fa-refresh"></i> Reset</button>
                                            </form>
							</div>
						</div>
					</div>
				</div>
				<div class="clearfix"></div>
				<p>&nbsp;</p>
			</div>
		</div>
	</div>
	<div class="clear"></div>
</section>

<?php Template::load('footer'); ?>
