<?= Template::loadChild('admin/account/parts/header', ['title' => 'Setup Two Factor Authentication']) ?>

<h3>Two-Factor Authentication Setup</h3>
<?= Template::loadChild('public/alerts') ?>

<div style="margin-top: 15px;">
    <?php if (isset($qr_url)): ?>
        <p>
            To set up two-factor authentication, scan or import the QR code below with your preferred 2FA app (e.g., Google Authenticator, Microsoft Authenticator, Authy). 
        </p>
        <p>
            On a Mac or iOS device, you can right-click (or long-press on mobile) the QR code to add it to your built-in Passwords app. That way, access to your account will be secured using your OS' authentication method (e.g., Touch ID, Face ID).
        </p>
        <p>
            If you're unable to scan the code, you may manually enter the secret key shown below in your authenticator app. 
        </p>
        <p>
            After the QR code is scanned or the secret is entered, use the app-generated code to confirm and enable 2FA.
        </p>
        <div>
            <img src="<?= $qr_url ?>" alt="QR Code for 2FA code" />
        </div>
        <p>
            Secret: <?= Session::get('2fa_setup')['secret'] ?>
        </p>
        <p>
            If you plan to use the formr R package, you can also use the secret key to set up 2FA there. Now is a good time, because you will not be able to look up the secret key again. Use the following command to set up 2FA:
        </p>
        <p>
        <i class="fa fa-copy"></i> <code class="copy-on-click">formr::formr_store_keys(account_name = "https://<?= Config::get('admin_domain') ?>", email = "<?= $username ?>", secret_2fa = "<?= Session::get('2fa_setup')['secret'] ?>")</code>
        </p>

        <form id="2faSetup" name="2faSetup" method="post" action="<?= admin_url('account/setup-two-factor') ?>">
            <?= formr_csrf_token() ?>
            <div class="form-group label-floating">
                <label class="control-label text-center" for="code"><i class="fa fa-code"></i> Enter Confirmation Code</label>
                <input class="form-control" type="text" id="code" name="code">
            </div>
            <div class="text-center">
                <button type="submit" class="btn btn-primary btn-raised">Enable 2FA</button>
            </div>
        </form>
    <?php else: ?>

        <p  class="text-left"> Two-factor authentication adds an extra layer of security to your account. </p>
        <p class="text-left"> Once enabled, you'll need both your password and a code from your authenticator app to log in.</p>
        
        <form method="post" action="<?= admin_url('account/setup-two-factor') ?>">
            <input type="hidden" name="setup" value="true" />
            <div class="text-center">
                <button type="submit" class="btn btn-primary btn-raised">Begin 2FA Setup</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?= Template::loadChild('admin/account/parts/footer') ?>