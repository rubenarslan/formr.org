<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1>Global Settings</h1>
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="box box-primary">

                    <div class="box-body">
                        <?php Template::loadChild('public/alerts'); ?>
                        <div class="nav-tabs-custom">
                            <ul class="nav nav-tabs">
                                <li class="active"><a href="#content" data-toggle="tab" aria-expanded="true">Content</a></li>
                                <li><a href="#links" data-toggle="tab" aria-expanded="true">URLs (Links)</a></li>
                                <li><a href="#js" data-toggle="tab" aria-expanded="false">JavaScript Configuration</a></li>
                            </ul>
                            <div class="tab-content">
                                <div class="tab-pane active" id="content">
                                    <form class="form-horizontal" enctype="multipart/form-data" method="post" action="<?php echo admin_url('advanced/settings'); ?>">
                                        <p class="pull-right">
                                            <input type="submit" name="content_settings" value="Save" class="btn btn-primary save_settings">
                                        </p>
                                        
                                        <h4>Publications</h4>
                                        <div class="col-md-12">
                                            <div class="form-group">
                                                <label>Publications (HTML Content)</label>
                                                <textarea data-editor="html" placeholder="Publications (HTML Content)" name="content:publications" rows="40" cols="80" class="big_ace_editor form-control"><?= h(array_val($settings, 'content:publications')); ?></textarea>
                                            </div>
                                            <div class="form-group">
                                                <label>Footer Imprint (HTML Content)</label>
                                                <textarea data-editor="html" placeholder="Publications (HTML Content)" name="content:footerimprint" rows="10" cols="80" class="big_ace_editor form-control"><?= h(array_val($settings, 'content:footerimprint')); ?></textarea>
                                            </div>
                                        </div>
                                        <div class="clear clearfix"></div>
                                    </form>
                                    
                                </div>
                                <!-- /.tab-pane -->
                                
                                <div class="tab-pane" id="links">
                                    <form class="form-horizontal" enctype="multipart/form-data" method="post" action="<?php echo admin_url('advanced/settings'); ?>">
                                        <p class="pull-right">
                                            <input type="submit" name="links_settings" value="Save" class="btn btn-primary save_settings">
                                        </p>
                                        
                                        <h4>Footer</h4>
                                        <div class="col-md-12">
                                            
                                            <div class="form-group">
                                                <label>Footer > Privacy Policy URL</label>
                                                <input type="text" maxlength="1000" placeholder="" name="links:policyurl" class="form-control" value="<?= h(array_val($settings, 'links:policyurl')); ?>" />
                                            </div>
                                            <div class="form-group">
                                                <label>Footer > Logo Image URL</label>
                                                <input type="text" maxlength="1000" placeholder="" name="links:logourl" class="form-control" value="<?= h(array_val($settings, 'links:logourl')); ?>" />
                                            </div>
                                            <div class="form-group">
                                                <label>Footer > Logo Link URL</label>
                                                <input type="text" maxlength="1000" placeholder="" name="links:logolink" class="form-control" value="<?= h(array_val($settings, 'links:logolink')); ?>" />
                                            </div>
                                        </div>
                                        <div class="clear clearfix"></div>
                                    </form>
                                    
                                </div>
                                <!-- /.tab-pane -->
                                
                                <div class="tab-pane" id="js">
                                    <form class="form-horizontal" enctype="multipart/form-data"  id="content-settings" method="post" action="<?php echo admin_url('advanced/settings'); ?>">
                                        <p class="pull-right">
                                            <input type="submit" name="js_config" value="Save" class="btn btn-primary save_settings">
                                        </p>
                                        <h4><i class="fa fa-javascript"></i> Cookie Consent Configuration</h4>
                                        <p>
                                            Define a JSON object that will be used to configure the cookie consent popup. Please see @doc for a sample configuration
                                        </p>
                                        <div class="form-group col-md-12">
                                            <textarea data-editor="javascript" placeholder="Enter your custom JS here" name="js:cookieconsent" rows="15" cols="80" class="big_ace_editor form-control"><?= h(array_val($settings, 'js:cookieconsent')); ?></textarea>
                                        </div>
                                        <div class="clear clearfix"></div>
                                    </form>
                                    
                                </div>
                                <!-- /.tab-pane -->
                            </div>
                            <!-- /.tab-content -->
                        </div>
                    </div>
                    <!-- /.box-body -->

                </div>

            </div>
        </div>

        <div class="clear clearfix"></div>
    </section>
    <!-- /.content -->
</div>

<?php
Template::loadChild('admin/run/run_modals', array('reminders' => array()));
Template::loadChild('admin/footer');
?>