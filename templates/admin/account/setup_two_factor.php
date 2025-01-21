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

                                        <h3>Two-Factor Authentication Setup</h3>
                                        <?= Template::loadChild('public/alerts') ?>

                                        <div style="margin-top: 15px;">
                                            <?php if (isset($qr_url)): ?>
                                                <p>Scan the QR code below with your 2FA app (e.g. Google Authenticator) and enter the code displayed in the app to enable 2FA.</p>
                                                <div>
                                                    <img src="<?= $qr_url ?>" alt="QR Code for 2FA code" /> 
                                                </div>  
                                                <p>
                                                    Secret: <?= Session::get('2fa_setup')['secret'] ?>
                                                </p>
                                                
                                                <form id="2faSetup" name="2faSetup" method="post" action="<?= admin_url('account/setup-two-factor') ?>">
                                                    <div class="form-group label-floating">
                                                        <label class="control-label text-center" for="code"><i class="fa fa-code"></i> Enter Confirmation Code</label>
                                                        <input class="form-control" type="text" id="code" name="code">
                                                    </div>
                                                    <div class="text-center">
                                                        <button type="submit" class="btn btn-primary btn-raised">Enable 2FA</button>
                                                    </div>
                                                </form>
                                            <?php else: ?>
                                                <p class="text-left" style="margin-top: 25px; width: 100%; margin: 0 auto;">Two-factor authentication adds an extra layer of security to your account. Once enabled, you'll need both your password and a code from your authenticator app to log in.</p>
                                                <form method="post" action="<?= admin_url('account/setup-two-factor') ?>">
                                                    <input type="hidden" name="setup" value="true" />
                                                    <div class="text-center">
                                                        <button type="submit" class="btn btn-primary btn-raised">Begin 2FA Setup</button>
                                                    </div>
                                                </form>
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