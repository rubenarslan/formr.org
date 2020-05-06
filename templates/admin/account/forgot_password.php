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

                                        <h2>Forgot Password?</h2>
                                        <h4 class="text-left" style="line-height: 25px;">Enter your email below and a link to reset your password will be sent to you.</h4>
                                        <?= Template::loadChild('public/alerts') ?>

                                        <div style="margin-top: 55px;">

                                            <form class="" id="login" name="login" method="post" action="">
                                                <div class="form-group label-floating">
                                                    <label class="control-label" for="email"><i class="fa fa-envelope" required></i> Email</label>
                                                    <input class="form-control" type="email" id="email" name="email">
                                                </div>
                                                <button class="btn btn-sup btn-material-pink btn-raised"><i class="fa fa-send"></i> Send Link</button>
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