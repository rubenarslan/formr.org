<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1><?php echo $run->name; ?> <small><a target="_blank" title="The official link to your run, which you can share with prospective users." href="<?php echo run_url($run->name, null, null) ?>"><?php echo run_url($run->name, null, null) ?></a></small> </h1>
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
                        <h3 class="box-title">Run Overview </h3>
                    </div>
                    <div class="box-body">
                        <?php Template::loadChild('public/alerts'); ?>

                        <?php if (!empty($overview_script)): ?>
                            <div>
                                <h4>
                                    <i class="fa fa-eye"></i> <?= $overview_script->title ?>
                                    <small>
                                        <?= $user_overview['users_finished'] ?>  finished users,
                                        <?= $user_overview['users_active'] ?> active users, 
                                        <?= $user_overview['users_waiting'] ?> <abbr title="inactive for at least a week">waiting</abbr> users
                                    </small>
                                </h4>
                                <?php echo $overview_script->parseBodySpecial(); ?>
                            </div>
                        <?php else: ?>
                            <p> <a href="<?= admin_run_url($run->name, 'settings') ?>"class="btn btn-default"><i class="fa fa-plus-circle"></i> Add an overview script</a></p>
                        <?php endif; ?>

                    </div>

                </div>
            </div>
        </div>

        <div class="clear clearfix"></div>
    </section>
    <!-- /.content -->
</div>

<?php Template::loadChild('admin/footer'); ?>