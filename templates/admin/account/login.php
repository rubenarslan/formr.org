<?= Template::loadChild('admin/account/parts/header', ['title' => 'Login']) ?>

<h2>Login to your Account</h2>
<?= Template::loadChild('public/alerts') ?>

<div style="margin-top: 55px;">

    <form class="" id="login" name="login" method="post" action="<?= admin_url('account/login') ?>">
        <div class="form-group label-floating">
            <label class="control-label" for="email"><i class="fa fa-envelope"></i> Email</label>
            <input class="form-control" type="email" id="email" name="email">
        </div>
        <div class="form-group label-floating">
            <label class="control-label" for="email"><i class="fa fa-lock"></i> Password</label>
            <input class="form-control" type="password" id="pass" name="password">
        </div>

        <button type="submit" class="btn btn-sup btn-material-pink btn-raised">Sign In</button>
        <p>&nbsp;</p>
        <a href="<?php echo admin_url('account/forgot-password'); ?>"><strong>Forgot password?</strong></a>
        <p>&nbsp;</p>
        <a href="<?php echo admin_url('account/register'); ?>" class="btn btn-sup btn-material-pink btn-raised">Sign Up</a>
    </form>
</div>

<?= Template::loadChild('admin/account/parts/footer') ?>