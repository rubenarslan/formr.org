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
                                    <div class="login-form">
                                        <span class="close"><a href="<?= site_url() ?>">&times</a></span>
                                        <div>
                                            <a href="<?= site_url() ?>" class="login-form-logo"><?=Config::get('brand')?></a>
                                        </div>
                                        
                                        <h2>Verify your identity via 2FA</h2>
                                        <?= Template::loadChild('public/alerts') ?>
                                        
                                        <div style="margin-top: 55px;">
                                            <div class="alert alert-info text-left">
                                                <p><i class="fa fa-info-circle"></i> Please open your two-factor authentication app (like Google Authenticator) and enter the 6-digit code shown for formr.</p>
                                                <p>If you have lost your 2FA device, you can use your backup codes to login.</p>
                                                <p>If you have lost your backup codes as well, you will need to contact an instance administrator to restore access to your account.</p>
                                                <p>Make sure to enter the code before it expires - codes typically refresh every 30 seconds.</p>
                                            </div>
                                            <form class="" id="loginf2a" name="login2fa" method="post" action="<?= admin_url('account/twoFactor') ?>">
                                                <div class="form-group label-floating">
                                                    <label class="control-label" for="email"><i class="fa fa-envelope"></i> 2FA Code</label>
                                                    <input class="form-control" type="text" id="2facode" name="2facode" autocomplete="one-time-code">
                                                </div>

                                                <button type="submit" class="btn btn-sup btn-material-pink btn-raised">Login</button>
                                                <p>&nbsp;</p>
                                            </form>
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