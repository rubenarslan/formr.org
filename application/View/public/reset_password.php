<?php
Template::load('public/header');
?>

<section id="fmr-hero" class="js-fullheight full" data-next="yes">
    <div class="fmr-overlay"></div>
    <div class="container">
        <div class="fmr-intro js-fullheight">
            <div class="row">
                <div class="col-md-6 col-md-offset-3">
                    <div class="fmr-intro-text">
                        <div class="col-md-12">
                            <div class="login-form large well">
                                <h2>Reset Password</h2>
                                <?= Template::load('public/alerts') ?>
                                <p class="lead  no-margin">Your new password will be effective immediately</p>
                                <form class="login-form large" id="login" name="login" method="post" action="">
                                    <input required type="hidden" name="email" id="email" value="<?= htmlspecialchars($reset_data_email); ?>">
                                    <input required type="hidden" name="reset_token" id="reset_token" value="<?= htmlspecialchars($reset_data_token); ?>">

                                    <div class="form-group label-floating">
                                        <label class="control-label" for="pass"><i class="fa fa-lock"></i> Enter New Password (Choose a secure phrase)</label>
                                        <input class="form-control" type="password" id="pass" name="new_password" required>
                                    </div>
                                    <div class="form-group label-floating">
                                        <label class="control-label" for="pass2"><i class="fa fa-lock"></i> Confirm New Password </label>
                                        <input class="form-control" type="password" id="pass2" name="new_password_c" required>
                                    </div>
                                    <button class="btn btn-sup btn-material-pink btn-raised"><i class="fa fa-refresh"></i> Reset</button>
                                </form>
                            </div>
                        </div>
                        <div class="clearfix"></div>
                    </div>
                </div>
                <div class="clearfix"></div>
                <p>&nbsp;</p>
            </div>
        </div>
    </div>
    <div class="clear"></div>
</section>

<?php Template::load('public/footer'); ?>
