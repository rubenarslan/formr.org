<?= Template::loadChild('admin/account/parts/header', ['title' => 'Setup Two Factor Authentication']) ?>

<h4>Two Factor Authentication Verification</h4>
<?= Template::loadChild('public/alerts') ?>

<div style="margin-top: 35px;">
    <div class="alert alert-info text-left">
        <p><i class="fa fa-info-circle"></i> <br /> Enter the 6 digit code show in your Two Factor Authentication App for formr.</p>
        <p>Make sure to enter the code before it expires - codes typically refresh every 30 seconds.</p>
    </div>
    
    <form class="" id="loginf2a" name="login2fa" method="post" action="<?= admin_url('account/twoFactor') ?>">
        <?= formr_csrf_token() ?>
        <div class="form-group label-floating">
            <label class="control-label" for="email">Enter Code</label>
            <input class="form-control" type="text" id="2facode" name="2facode" autocomplete="one-time-code">
        </div>

        <button type="submit" class="btn btn-sup btn-material-pink btn-raised">Login</button>
        <p>&nbsp;</p>
    </form>
    <div class="text-left">
        <p><i class="fa fa-info-circle"></i> If you have lost your 2FA device, you can use your backup codes to login.</p>
        <p><i class="fa fa-info-circle"></i> If you have lost your backup codes as well, you will need to contact an instance administrator to restore access to your account.</p>
    </div>
</div>

<?= Template::loadChild('admin/account/parts/footer') ?>