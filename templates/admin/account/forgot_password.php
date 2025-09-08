<?= Template::loadChild('admin/account/parts/header', ['title' => 'Password Forgotten']) ?>

<h2>Forgot Password?</h2>
<h4 class="text-left" style="line-height: 25px;">Enter your email below and a link to reset your password will be sent to you.</h4>
<?= Template::loadChild('public/alerts') ?>

<div style="margin-top: 55px;">

    <form class="" id="login" name="login" method="post" action="">
        <?= formr_csrf_token() ?>
        <div class="form-group label-floating">
            <label class="control-label" for="email"><i class="fa fa-envelope" required></i> Email</label>
            <input class="form-control" type="email" id="email" name="email">
        </div>
        <button class="btn btn-sup btn-material-pink btn-raised"><i class="fa fa-send"></i> Send Link</button>
    </form>
</div>

<?= Template::loadChild('admin/account/parts/footer') ?>