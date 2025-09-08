<form class="form-horizontal" enctype="multipart/form-data" method="post" action="<?php echo admin_url('advanced/content_settings'); ?>">
    <?= formr_csrf_token() ?>
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
    </div>
    <div class="clear clearfix"></div>
</form>