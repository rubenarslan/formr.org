<?= Template::loadChild('admin/account/parts/header', ['title' => 'Sign Up']) ?>

<?= Template::loadChild('public/alerts') ?>
<?php if (Site::getSettings('signup:allow', 'true') !== 'true'): ?>
    <div>
        <?php echo Site::getSettings('signup:message', '') ?>
    </div>
<?php else: ?>
    <h2>Sign up!</h2>
    <div style="margin-top: 55px;">

        <form class="" id="register" name="register" method="post" action="">
            <div class="form-group label-floating">
                <label class="control-label" for="email"><i class="fa fa-envelope"></i> Email</label>
                <input class="form-control" type="email" id="email" name="email" required autocomplete="new-password">
            </div>
            <div class="form-group label-floating">
                <label class="control-label" for="pass"><i class="fa fa-lock"></i> Choose a Password</label>
                <input class="form-control" type="password" id="pass" name="password" required autocomplete="new-password">
            </div>

            <?php if (Site::getSettings("signup:enable_referral_token", 'true') === 'true'): ?>
                <div class="form-group label-floating">
                    <label class="control-label" for="token"><i class="fa fa-gift"></i> Referral token (if available)</label>
                    <input class="form-control" type="text" id="token" name="referrer_code" autocomplete="off">
                    <input type="hidden" name="<?= Session::REQUEST_TOKENS ?>" value="<?= Session::getRequestToken() ?>" />
                </div>

                <div>
                    <p>If you have a valid token, you'll be able to create studies once you confirm your email address. If you don't have a valid token, sign up first and then write an email to this instance's administrator at <?php $support_email = Site::getSettings('content:docu:support_email', 'no@email.provided'); ?><a href="mailto:<?= $support_email ?>"><?= $support_email ?></a>.</p>
                </div>
            <?php endif; ?>

            <div>
                <label><input type="checkbox" name="agree_tos" value="1" required> I agree to the <a href="<?=site_url("terms_of_service") ?>">terms and conditions</a> and the <a href="<?=run_url("privacy") ?>">privacy</a> policy.</label>
            </div>

            <?php if (Config::get('2fa.enabled', true) && Config::get('2fa.allow_during_signup', false)): ?>
                <div class="form-group">
                    <label><input type="checkbox" name="setup_2fa" value="1" <?php echo Config::get('2fa.required', false) ? 'required checked disabled' : ''; ?>>
                        Enable Two-Factor Authentication (2FA)
                        <?php if (Config::get('2fa.required', false)): ?>
                            <span class="text-muted">(required)</span>
                        <?php endif; ?>
                    </label>
                    <p class="help-block">You will be prompted to set up 2FA after email verification.</p>
                </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-sup btn-material-pink btn-raised"><i class="fa fa-pencil fa-fw"></i> Sign Up</button>
        </form>
        <p>&nbsp;</p>

    </div>
<?php endif; ?>
<div>
    <p>Already signed up?</p>
    <p><a href="<?php echo admin_url('account/login'); ?>" class="btn btn-sup btn-material-pink btn-raised">Sign In</a></p>
</div>

<?= Template::loadChild('admin/account/parts/footer') ?>