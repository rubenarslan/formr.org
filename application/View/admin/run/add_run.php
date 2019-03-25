<?php Template::load('admin/header'); ?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1>Runs <small>Add New</small></h1>
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="col-md-6 col-md-offset-3">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Create new run</h3>
                </div>

                <form action="<?php echo admin_url('run/add_run'); ?>" role="form" enctype="multipart/form-data"  id="add_study" name="add_study" method="post">
                    <div class="box-body">
                        <?php Template::load('public/alerts'); ?>

                        <div class="callout callout-info">
                            <h4>Enter Run shorthand</h4>
                            <ul class="fa-ul fa-ul-more-padding">
                                <li><i class="fa-li fa fa-exclamation-triangle"></i> This is the name that users will see in their browser's address bar for your study, possibly elsewhere too.</li>
                                <li><i class="fa-li fa fa-unlock"></i> It can be changed later, but it also changes the link to your study, so don't change it once you're live.</li>
                                <li><i class="fa-li fa fa-lightbulb-o"></i> Ideally, it should be the memorable name of your study.</li>
                                <li><i class="fa-li fa fa-edit"></i> Name should contain only alpha-numeric characters and no spaces. It needs to start with a letter.</li>
                            </ul>
                        </div>
                        <div class="form-group">
                            <input name="run_name" type="text" class="form-control" placeholder="Name (a to Z, 0 to 9 and -)" pattern="^[a-zA-Z][a-zA-Z0-9-]*$">
                        </div>
                    </div>
                    <div class="clear clearfix"></div>
                    <!-- /.box-body -->

                    <div class="box-footer">
                        <button type="submit" class="btn btn-primary"><i class="fa fa-rocket"></i> Create run</button>
                    </div>
                </form>
            </div>
            <p>&nbsp;</p>
            <a href="<?php echo site_url('documentation/#run_module_explanations'); ?>" target="_blank"><i class="fa fa-question-circle"></i> more help on creating runs</a>
        </div>

        <div class="clear clearfix"></div>
    </section>
    <!-- /.content -->
</div>
<!-- /.content-wrapper -->

<?php Template::load('admin/footer'); ?>

