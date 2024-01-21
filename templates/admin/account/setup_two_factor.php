<?php
    use chillerlan\QRCode\QRCode;
?>
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

                                        <h2>Setup Two Factor</h2>
                                        <?= Template::loadChild('public/alerts') ?>

                                        <div style="margin-top: 55px;">
                                            <?php
                                                $data = 'otpauth://totp/test?secret=' . Session::get('2fa_secret') . '&issuer=formr';
                                                echo '<img width="80%" src="'.(new QRCode)->render($data).'" alt="QR Code for 2FA code" />';
                                            ?><br/>
                                            Secret: <?= Session::get('2fa_secret') ?>
                                            <form>
                                                <input type="text" name="code" placeholder="Enter code for confirmation" />
                                                <input type="submit" value="Enable 2FA" />
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