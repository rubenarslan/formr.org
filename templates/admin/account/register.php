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

                                        <h2>Sign-up to formr. It's free!</h2>
                                        <?= Template::loadChild('public/alerts') ?>

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
                                                <div class="form-group label-floating">
                                                    <label class="control-label" for="token"><i class="fa fa-gift"></i> Referral token (if available)</label>
                                                    <input class="form-control" type="text" id="token" name="referrer_code"  autocomplete="off">
                                                </div>
                                                
                                                <div>
                                                    <p>If you don't have a referral token, sign up first and then write us an <a title=" We're excited to have people try this out, so you'll get a test account, if you're human. But let us know a little about what you plan to do (and sign up first)." class="schmail" href="mailto:IMNOTSENDINGSPAMTOruben.arslan@that-big-googly-eyed-email-provider.com?subject=<?= rawurlencode("formr private beta"); ?>&amp;body=<?= rawurlencode("If you are not a robot, I have high hopes that you can figure out how to get my proper email address from the above.Hi there!

I'd like to create studies using formr. I'm totally not a dolphin.

I confirm that I have already registered with the email address from which I'm sending this request. 

I'm affiliated with the University of Atlantis.

This is what I want to use formr for:
[x] find out more about land mammals
[x] plan cetacean world domination 
[ ] excessively use your server resources

Squee'ek uh'k kk'kkkk squeek eee'eek!
Not a Dolphin
"); ?>">email</a> to ask for the right to create studies. If you have a token, you'll be able to create studies once you confirm your email address.</p>
                                                </div>

                                                <button type="submit" class="btn btn-sup btn-material-pink btn-raised"><i class="fa fa-pencil fa-fw"></i> Sign Up</button>
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