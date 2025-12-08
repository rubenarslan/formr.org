<?= Template::loadChild('admin/account/parts/header', ['title' => 'Password Reset']) ?>

<h2>Reset Password</h2>
<h4 class="text-left" style="line-height: 25px;">Your new password will be effective immediately.</h4>
<?= Template::loadChild('public/alerts') ?>

<div style="margin-top: 55px;">
    <form class="" id="login" name="login" method="post" action="">
        <?= formr_csrf_token() ?>
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

<?= Template::loadChild('admin/account/parts/footer') ?>