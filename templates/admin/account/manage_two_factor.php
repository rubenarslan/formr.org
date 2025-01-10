<!DOCTYPE html>
<?php
    use chillerlan\QRCode\QRCode;
?>
<html>
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <title>formr admin</title>
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

                                        <h2>Manage Two-Factor Authentication</h2>
                                        <?= Template::loadChild('public/alerts') ?>

                                        <div style="margin-top: 15px;">
                                            <div class="alert alert-info">
                                                Two-factor authentication is currently enabled for your account.
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6">
                                                    <h3>Reset 2FA</h3>
                                                    <p>If you need to set up 2FA on a new device, you can reset it here. You'll need to verify your current 2FA code first.</p>
                                                    <form id="2faReset" name="2faReset" method="post" action="<?= admin_url('account/setupTwoFactor') ?>">
                                                        <input type="text" name="reset_code" class="form-control" placeholder="Enter current 2FA code" required />
                                                        <input type="hidden" name="reset" value="1" />
                                                        <button type="submit" class="btn btn-warning">Reset 2FA</button>
                                                    </form>
                                                </div>

                                                <div class="col-md-6">
                                                    <h3>Disable 2FA</h3>
                                                    <?php if(Config::get('2fa.required', false)): ?>
                                                        <div class="alert alert-warning">
                                                            Two-factor authentication is required by this instance and cannot be disabled.
                                                        </div>
                                                    <?php else: ?>
                                                        <p>If you want to disable 2FA completely, you can do so here. You'll need to verify your current 2FA code first.</p>
                                                        <form id="2faDisable" name="2faDisable" method="post" action="<?= admin_url('account/setupTwoFactor') ?>">
                                                            <input type="text" name="disable_code" class="form-control" placeholder="Enter current 2FA code" required />
                                                            <input type="hidden" name="disable" value="1" />
                                                            <button type="submit" class="btn btn-danger">Disable 2FA</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
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
                <div class="pull-right hidden-xs"></div>
                <strong>Copyright &copy; <?= date('Y') ?> <a href="<?= site_url(); ?>">formr</a></strong>
            </footer>
        </div>
    </body>
</html> 