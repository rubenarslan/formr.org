<?= Template::loadChild('admin/account/parts/header', ['title' => 'Setup Two Factor Authentication']) ?>

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