<form class="form-horizontal" enctype="multipart/form-data" method="post" action="<?php echo admin_url('advanced/content_settings'); ?>">
    <p class="pull-right">
        <input type="submit" name="content_settings" value="Save" class="btn btn-primary save_settings">
    </p>

    <div class="col-md-12">

        <div class="form-group">
            <label>Privacy Policy URL</label>
            <input type="text" maxlength="1000" name="footer:link:policyurl" class="form-control" value="<?= h(array_val($settings, 'footer:link:policyurl')); ?>" />
        </div>
        <div class="form-group">
            <label>Logo Image URL</label>
            <input type="text" maxlength="1000" name="footer:link:logourl" class="form-control" value="<?= h(array_val($settings, 'footer:link:logourl')); ?>" />
        </div>
        <div class="form-group">
            <label>Logo Link URL</label>
            <input type="text" maxlength="1000" name="footer:link:logolink" class="form-control" value="<?= h(array_val($settings, 'footer:link:logolink')); ?>" />
        </div>
        <div class="form-group">
            <label>Imprint (HTML Content)</label>
            <textarea data-editor="html" placeholder="Publications (HTML Content)" name="footer:imprint" rows="10" cols="80" class="big_ace_editor form-control"><?= h(array_val($settings, 'footer:imprint')); ?></textarea>
        </div>
    </div>
    <div class="clear clearfix"></div>
</form>