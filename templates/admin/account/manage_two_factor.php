<?= Template::loadChild('admin/account/parts/header', ['title' => 'Two Factor Authentication']) ?>

<h2>Two-Factor Authentication</h2>
<?= Template::loadChild('public/alerts') ?>

<div style="margin-top: 10px;">
    <div class="row">
        <form id="2faSetup" name="2faSetup" method="post" action="<?= admin_url('account/manage-two-factor') ?>">
            <div class="col-md-12 text-left twofa-setup">
                <ul class="list-group list-group-light">
                    <li class="list-group-item">
                        <div class="input-group">
                            <input type="radio" name="manage_2fa" value="reset" id="reset-2fa" />
                            <label for="reset-2fa">
                                <p>Reset Two Factor Authentication</p>
                            </label>
                        </div>

                        <p class="hint">If you need to set up 2FA on a new device, you can reset it here. You'll need to verify your current 2FA code first.</p>
                    </li>
                    <?php if (!Config::get('2fa.required', false)): ?>
                        <li class="list-group-item">
                            <div class="input-group">
                                <input type="radio" name="manage_2fa" value="disable" id="disable-2fa" />
                                <label for="disable-2fa">
                                    <p>Disable Two Factor Authentication</p>
                                </label>
                            </div>

                            <p class="hint">If you want to disable 2FA completely, you can do so here. You'll need to verify your current 2FA code first..</p>
                        </li>
                    <?php endif ?>
                </ul>


                <div class="form-group label-floating">
                    <label class="control-label text-center" for="code"><i class="fa fa-code"></i> Enter Confirmation Code</label>
                    <input class="form-control" type="text" id="code" name="code">
                </div>
                <div class="text-center">
                    <a href="<?= admin_url('account#2fa') ?>" class="btn btn-default btn-raised" style="margin-right: 15px;">Cancel</a>
                    <button type="submit" class="btn btn-primary btn-raised">Proceed</button>
                </div>
        </form>
    </div>
</div>

<style>
    .twofa-setup .input-group {
        display: flex;
        align-items: center;
    }

    .twofa-setup .input-group * {
        margin: 0;
    }

    .twofa-setup .input-group input {
        margin-right: 5px;
    }

    .twofa-setup .input-group label {
        color: #000;
    }

    .twofa-setup .hint {
        font-size: 14px;
        margin: 5px 0px;
    }

    .twofa-setup .list-group-item {
        margin-bottom: 20px;
    }
</style>

<?= Template::loadChild('admin/account/parts/footer') ?>