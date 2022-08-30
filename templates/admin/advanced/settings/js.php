<form class="form-horizontal" enctype="multipart/form-data" method="post" action="<?php echo admin_url('advanced/content_settings'); ?>">
    <p class="pull-right">
        <input type="submit" name="content_settings" value="Save" class="btn btn-primary save_settings">
    </p>

    <div class="col-md-12">

        <h4><i class="fa fa-javascript"></i> Cookie Consent Configuration</h4>
        <p>
            Define a JSON object that will be used to configure the cookie consent popup. Please see @doc for a sample configuration
        </p>
        <div class="form-group col-md-12">
            <textarea data-editor="javascript" placeholder="Enter your custom JS here" name="js:cookieconsent" rows="15" cols="80" class="big_ace_editor form-control"><?= h(array_val($settings, 'js:cookieconsent')); ?></textarea>
        </div>
    </div>
    <div class="clear clearfix"></div>
</form>