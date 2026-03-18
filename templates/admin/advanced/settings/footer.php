<form class="form-horizontal" id="content_settings" method="post" action="<?php echo admin_url('advanced/content-settings'); ?>">
    <p class="pull-right">
        <input type="submit" name="submit_settings" value="Save" class="btn btn-primary save_settings">
    </p>

    <div class="form-group">
        <label>Logo URL</label>
        <input type="text" maxlength="1000" name="footer:link:logourl" class="form-control" value="<?= h(array_val($settings, 'footer:link:logourl')); ?>" />
        <p>URL to the logo image that will be displayed in the footer.</p>
    </div>

    <div class="form-group">
        <label>Logo Link</label>
        <input type="text" maxlength="1000" name="footer:link:logolink" class="form-control" value="<?= h(array_val($settings, 'footer:link:logolink')); ?>" />
        <p>URL that the logo will link to when clicked.</p>
    </div>

    <div class="form-group">
        <label>Imprint</label>
        <textarea data-editor="html" placeholder="Imprint (HTML Content)" name="footer:imprint" rows="10" cols="80" class="big_ace_editor form-control"><?= h(array_val($settings, 'footer:imprint')); ?></textarea>
        <p>Imprint information to be displayed in the footer.</p>
    </div>
</form>