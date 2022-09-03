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
                                    <div class="login-form">
                                        <span class="close"><a href="<?= site_url() ?>">&times</a></span>
                                        <div>
                                            <a href="<?= site_url() ?>" class="login-form-logo"><?=Config::get('brand')?></a>
                                        </div>

                                        <h2>Sign-up to formr. It's free!</h2>
                                        <?= Template::loadChild('public/alerts') ?>

                                        <div style="margin-top: 55px;">
                                            <?php if (Site::getSettings('signup:allow', 'true') === 'true'): ?>
                                            <form class="" id="register" name="register" method="post" action="">
                                                <div class="form-group label-floating">
                                                    <label class="control-label" for="email"><i class="fa fa-envelope"></i> Email</label>
                                                    <input class="form-control" type="email" id="email" name="email" required autocomplete="new-password">
                                                </div>
                                                <div class="form-group label-floating">
                                                    <label class="control-label" for="pass"><i class="fa fa-lock"></i> Choose a Password</label>
                                                    <input class="form-control" type="password" id="pass" name="password" required  autocomplete="new-password">
                                                </div>
                                                <div class="form-group label-floating">
                                                    <label class="control-label" for="token"><i class="fa fa-gift"></i> Referral token (if available)</label>
                                                    <input class="form-control" type="text" id="token" name="referrer_code"  autocomplete="off">
                                                    <input type="hidden" name="<?= Session::REQUEST_TOKENS ?>" value="<?= Session::getRequestToken() ?>" />
                                                </div>
                                                
                                                <div>
                                                    <p>If you don't have a referral token, sign up first and then write us an <a title=" We're excited to have people try this out, so you'll get a test account, if you're human. But let us know a little about what you plan to do (and sign up first)." class="schmail" href="mailto:accounts@formr.org?subject=<?= rawurlencode("formr private beta"); ?>&amp;body=<?= rawurlencode(Template::get('email/reg-account.ftpl')); ?>">email</a> to ask for the right to create studies. If you have a token, you'll be able to create studies once you confirm your email address.</p>
                                                </div>

                                                <button type="submit" class="btn btn-sup btn-material-pink btn-raised"><i class="fa fa-pencil fa-fw"></i> Sign Up</button>
                                            </form>
                                            <?php else: ?>
                                            <div> <?php echo Site::getSettings('signup:message', '') ?></div>
                                            <?php endif; ?>
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