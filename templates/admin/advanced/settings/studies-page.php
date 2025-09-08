<form class="form-horizontal" enctype="multipart/form-data" method="post" action="<?php echo admin_url('advanced/content_settings'); ?>">
    <?= formr_csrf_token() ?>
    <p class="pull-right">
        <input type="submit" name="content_settings" value="Save" class="btn btn-primary save_settings">
    </p>
    
    <?php
        $val = array_val($settings, 'content:studies:show', "true") === "true";
        $checked = $val ? 'checked="checked"' : ''; 
    ?>
    <div class="col-md-12">
        <div class="form-group">
            <div class="form-check">
                <input type="hidden" name="content:studies:show" value="false" />
                <input class="form-check-input" <?= $checked ?> type="checkbox" value="true" name="content:studies:show" id="studies-page-show" />
                <label for="studies-page-show" class="form-check-label">
                    Show 'Studies' Page
                </label>
            </div>
        </div>
    </div>
    <div class="clear clearfix"></div>
</form>