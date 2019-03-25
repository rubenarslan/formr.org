<?php Template::load('admin/header'); ?>

<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1><?php echo $run->name; ?> <small><a target="_blank" title="The official link to your run, which you can share with prospective users." href="<?php echo run_url($run->name, null, null) ?>"><?php echo run_url($run->name, null, null) ?></a></small> </h1>
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="row">
            <div class="col-md-2">
                <?php Template::load('admin/run/menu'); ?>
            </div>
            <div class="col-md-8">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Empty Run </h3>
                    </div>
                    <form role="form" action="<?php echo admin_run_url($run->name, 'empty_run'); ?>" method="post">
                        <div class="box-body">
                            <?php Template::load('public/alerts'); ?>

                            <div class="form-group">
                                <p class="control-label hastooltip" for="empty_confirm" title="this is required to avoid accidental deletions">Type the run's name to confirm that you want to delete all existing <span class="badge badge-success"><?= $users['sessions'] ?></span> users who progressed on average to position <span class="badge"><?= round($users['avg_position'], 2) ?></span>.</p>
                                <p>You should only use this feature before the study goes live, to get rid of testing remnants! Please backup your survey data individually before emptying a run.</p>

                            </div>
                            <div class="form-group">
                                <div class="controls">
                                    <div class="input-group">
                                        <span class="input-group-addon"><i class="fa fa-pencil-square"></i></span>
                                        <input class="form-control" name="empty_confirm" id="empty_confirm" type="text" placeholder="run name (see up left)" autocomplete="off">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- /.box-body -->

                        <div class="box-footer">
                            <button name="delete" class="btn btn-default btn-danger hastooltip" type="submit"><i class="fa fa-trash-o fa-fw"></i>  Empty entire run permanently </button>
                        </div>
                    </form>
                </div>

            </div>
        </div>

        <div class="clear clearfix"></div>
    </section>
    <!-- /.content -->
</div>

<?php Template::load('admin/footer'); ?>