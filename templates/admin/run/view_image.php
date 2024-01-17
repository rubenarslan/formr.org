<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1>View Image</h1>
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="row">
            <div class="col-md-2">
                <?php Template::loadChild('admin/run/menu'); ?>
            </div>
            <div class="col-md-10">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title"><?php echo $original; ?></h3>
                        <a href="javascript:void(0);" data-url="<?php echo $new; ?>" class="btn btn-sm btn-primary copy-url"><i class="fa fa-copy"></i> Copy URL</a>
                    </div>
                    <div class="box-body">
                        <?php echo replaceImgTags(array("", " ", $new, "")); ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="clear clearfix"></div>
    </section>
    <!-- /.content -->
</div>

<?php Template::loadChild('admin/footer'); ?>