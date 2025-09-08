<form class="form-horizontal" enctype="multipart/form-data" method="post" action="<?php echo admin_url('advanced/content_settings'); ?>">
    <?= formr_csrf_token() ?>
    <p class="pull-right">
        <input type="submit" name="content_settings" value="Save" class="btn btn-primary save_settings">
    </p>
    <?php
        $val = array_val($settings, 'content:publications:show', "true") === "true";
        $checked = $val ? 'checked="checked"' : ''; 
    ?>
    <div class="col-md-12">
        <div class="form-group col-md-12">
            <div class="form-check">
                <label class="form-check-label" for="pubs-page-show">
                    <input type="hidden" name="content:publications:show" value="false" />
                    <input id="pubs-page-show" class="form-check-input" <?= $checked?> type="checkbox" value="true" name="content:publications:show" />
                    Show 'Publications' Page 
                </label>
            </div>
        </div>
        <div class="form-group  col-md-12">
            <label>Publications (HTML Content)</label>
            <textarea data-editor="html" placeholder="Publications (HTML Content)" name="content:publications" rows="40" cols="80" class="big_ace_editor form-control"><?= h(array_val($settings, 'content:publications')); ?></textarea>
                                            
        </div>
    </div>
    <div class="clear clearfix"></div>
</form>