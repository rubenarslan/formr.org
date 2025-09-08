<form class="form-horizontal" enctype="multipart/form-data" method="post" action="<?php echo admin_url('advanced/content_settings'); ?>">
    <?= formr_csrf_token() ?>
    <p class="pull-right">
        <input type="submit" name="content_settings" value="Save" class="btn btn-primary save_settings">
    </p>

    <?php
        $val = array_val($settings, 'signup:allow', "true") === "true";
        $checked = $val ? 'checked="checked"' : ''; 
    ?>
    <div class="col-md-12">
        <div class="form-group">
            <div class="form-check">
                <label class="form-check-label" for="about-page-show"> 
                    <input type="hidden" name="signup:allow" value="false" />
                    <input id="about-page-show" class="form-check-input" <?= $checked ?> type="checkbox" value="true" name="signup:allow" />
                    Allow new users to sign-up.
                </label>
              </div>
        </div>
        <div class="form-group">
            <label class="control-label"> Administrator email address </label>
            <input class="form-control user-success" name="content:docu:support_email" value="<?= h(array_val($settings, 'content:docu:support_email', 'provide@email.in')); ?>" autocomplete="off">
            <p>Users would send requests to this email for admin accounts.</p>
        </div>

        <div class="form-group">
            <label>Terms of Service (HTML Content)</label>
            <textarea data-editor="html" placeholder="Message (HTML Content)" name="content:terms_of_service" rows="10" cols="80" class="big_ace_editor form-control"><?= h(array_val($settings, 'content:terms_of_service')); ?></textarea>
            <p>Terms of service that users are asked to agree to during sign up.</p>
        </div>

        <?php
        $val = array_val($settings, 'content:file_upload_require_active_consent', "false") === "true";
        $checked = $val ? 'checked="checked"' : ''; 
        ?>
        <div class="form-group">
            <div class="form-check">
                <label class="form-check-label">
                    <input type="hidden" name="content:file_upload_require_active_consent" value="false" />
                    <input class="form-check-input" <?= $checked?> type="checkbox" value="true" name="content:file_upload_require_active_consent" />
                    Require active consent (ticking checkbox) to file upload conditions.
                </label>
            </div>
        </div>
        <div class="form-group">
            <label>File upload conditions (HTML Content)</label>
            <textarea data-editor="html" placeholder="Message (HTML Content)" name="content:file_upload_terms" rows="10" cols="80" class="big_ace_editor form-control"><?= h(array_val($settings, 'content:file_upload_terms')); ?></textarea>
            <p>Conditions administrators agree to before uploading files in a study.</p>
        </div>

        <div class="form-group">
            <label>Service Message (HTML Content)</label>
            <textarea data-editor="html" placeholder="Message (HTML Content)" name="signup:message" rows="10" cols="80" class="big_ace_editor form-control"><?= h(array_val($settings, 'signup:message')); ?></textarea>
            <p>Message to display to users in case sign-ups are disabled.</p>
        </div>
    </div>
    <div class="clear clearfix"></div>
</form>