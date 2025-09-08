<form class="form-horizontal" id="content_settings" method="post" action="<?php echo admin_url('advanced/content-settings'); ?>">
    <?= formr_csrf_token() ?>
    <p class="pull-right">
        <input type="submit" name="submit_settings" value="Save" class="btn btn-primary save_settings">
    </p>

    <div class="form-group">
        <label>Privacy Policy (HTML Content)</label>
        <textarea data-editor="html" placeholder="Privacy Policy (HTML Content)" name="content:privacy_policy" rows="10" cols="80" class="big_ace_editor form-control"><?= h(array_val($settings, 'content:privacy_policy')); ?></textarea>
        <p>Privacy policy that users are asked to agree to during sign up. This will be displayed at <?= site_url("privacy_policy") ?>.</p>
    </div>

    <div class="form-group">
        <label><input type="checkbox" name="require_privacy_policy" value="1" <?= array_val($settings, 'require_privacy_policy') === '1' ? 'checked' : '' ?>> Require studies to have a privacy policy before they can go live</label>
        <p>If enabled, studies must have a non-empty privacy policy before they can be made public.</p>
    </div>
</form> 