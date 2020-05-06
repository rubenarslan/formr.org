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
                                        <div>
                                            <a href="<?= site_url() ?>" class="login-form-logo"><span>f</span>orm<span>{`r}</span></a>
                                        </div>

                                        <h2>Reset Password</h2>
                                        <h4 class="text-left" style="line-height: 25px;">Your new password will be effective immediately.</h4>
                                        <?= Template::loadChild('public/alerts') ?>

                                        <div style="margin-top: 55px;">
                                            <form class="" id="login" name="login" method="post" action="">
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