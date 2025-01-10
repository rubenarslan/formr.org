<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <title>formr admin</title>
        <!-- Tell the browser to be responsive to screen width -->
        <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
        <?php
        foreach ($css as $id => $files) {
            print_stylesheets($files, $id);
        }
        ?>
        
        <script>
            window.formr = <?php echo !empty($jsConfig) ? json_encode($jsConfig) : '{}' ?>;
        </script>
        
        <?php
        foreach ($js as $id => $files) {
            print_scripts($files, $id);
        }
        ?>
    </head>

    <body class="hold-transition skin-black">
        <div class="wrapper">
            <div class="content-wrapper">

                <section id="fmr-hero" class="js-fullheight full" data-next="yes">
                    <div class="fmr-overlay"></div>
                    <div class="container">
                        <div class="fmr-intro">
                            <div class="row">
                                <div class="fmr-intro-text">
                                    <div class="container top-alerts"><?= Template::loadChild('public/alerts') ?></div>
                                    <div class="login-form" style="height:750px">
                                        <span class="close"><a href="<?= site_url() ?>">&times</a></span>
                                        <div>
                                            <a href="<?= site_url() ?>" class="login-form-logo"><?=Config::get('brand')?></a>
                                        </div>

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
                                                    <input class="form-control" type="password" id="pass" name="password" required  autocomplete="new-password">
                                                </div>
                                                
                                                <?php if(Site::getSettings("signup:enable_referral_token", 'true') === 'true'): ?>
                                                <div class="form-group label-floating">
                                                    <label class="control-label" for="token"><i class="fa fa-gift"></i> Referral token (if available)</label>
                                                    <input class="form-control" type="text" id="token" name="referrer_code"  autocomplete="off">
                                                    <input type="hidden" name="<?= Session::REQUEST_TOKENS ?>" value="<?= Session::getRequestToken() ?>" />
                                                </div>
                                                
                                                <div>
                                                    <p>If you have a valid token, you'll be able to create studies once you confirm your email address. If you don't have a valid token, sign up first and then write an email to this instance's administrator at <?php $support_email = Site::getSettings('content:docu:support_email', 'no@email.provided'); ?><a href="mailto:<?= $support_email ?>"><?= $support_email ?></a>.</p>
                                                </div>
                                                <?php endif; ?>

                                                <div>
                                                    <label><input type="checkbox" name="agree_tos" value="1" required> I agree to the <a href="/tos">terms and conditions</a> and the <a href="/privacy">privacy</a> policy.
                                                </div>

                                                <?php if(Config::get('2fa.enabled', true) && Config::get('2fa.allow_during_signup', false)): ?>
                                                <div class="form-group">
                                                    <label><input type="checkbox" name="setup_2fa" value="1" <?php echo Config::get('2fa.required', false) ? 'required checked disabled' : ''; ?>> 
                                                    Enable Two-Factor Authentication (2FA) 
                                                    <?php if(Config::get('2fa.required', false)): ?>
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

                                    </div>
                                    <div class="clearfix"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="clear"></div>
                </section>

            </div>

            <footer class="main-footer">
                <!-- To the right -->
                <div class="pull-right hidden-xs"></div>
                <!-- Default to the left -->
                <strong>Copyright &copy; <?= date('Y') ?> <a href="<?= site_url(); ?>">formr</a></strong>
            </footer>

        </div>
        <!-- ./wrapper -->

    </body>
</html>