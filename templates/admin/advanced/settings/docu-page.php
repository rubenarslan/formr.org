<form class="form-horizontal" enctype="multipart/form-data" method="post" action="<?php echo admin_url('advanced/content_settings'); ?>">
    <p class="pull-right">
        <input type="submit" name="content_settings" value="Save" class="btn btn-primary save_settings">
    </p>

    <?php
        $val = array_val($settings, 'content:docu:show', "true") === "true";
        $checked = $val ? 'checked="checked"' : ''; 
    ?>
    <div class="col-md-12">
        <div class="form-group col-md-12">
            <div class="form-check">
                <label class="form-check-label" for="docu-page-show">
                    <input type="hidden" name="content:docu:show" value="false" />
                    <input id="docu-page-show" class="form-check-input" <?= $checked ?> type="checkbox" value="true" name="content:docu:show" />
                    Show 'Documentation' Page 
                </label>
            </div>
        </div>
        <div class="form-group  col-md-6">
            <label class="control-label"> Administrator email address </label>
            <input class="form-control user-success" name="content:docu:support_email" value="<?= h(array_val($settings, 'content:docu:support_email', 'provide@email.in')); ?>" autocomplete="off">
            <p>Users would send requests to this email for admin accounts.</p>
        </div>
    </div>
    <div class="clear clearfix"></div>
</form>