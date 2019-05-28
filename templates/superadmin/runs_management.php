<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1>Runs Management <small>Superadmin</small></h1>
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Formr Runs (<?= $count ?>) <small>Only runs with sessions are shown so might be different from total count</small></h3>
                    </div>
                    <div class="box-body table-responsive">
                        <?php Template::loadChild('public/alerts'); ?>
                        <?php if ($pdoStatement->rowCount()): ?>

                            <form method="post" action="" >
                                <table class='table table-striped'>
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Run Name</th>
                                            <th>User</th>
                                            <th>No. Sessions</th>
                                            <th>Cron Active</th>
                                            <th>Locked</th>
                                            <th>Sessions Queue</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($row = $pdoStatement->fetch(PDO::FETCH_ASSOC)): ?>
                                            <tr>
                                                <td><?= $row['run_id'] ?></td>
                                                <td><?= $row['name'] ?></td>
                                                <td><?= $row['email'] ?></td>
                                                <td><?= $row['sessions'] ?></td>
                                                <td>
                                                    <input type="hidden" name="runs[<?= $row['run_id'] ?>][run]" value="<?= $row['run_id'] ?>" />
                                                    <?php $checked = $row['cron_active'] ? 'checked="checked"' : null ?>
                                                    <input type="checkbox" name="runs[<?= $row['run_id'] ?>][cron_active]" value="<?= $row['cron_active'] ?>" <?= $checked ?> />
                                                </td>
                                                <td>
                                                    <?php $checked = $row['locked'] ? 'checked="checked"' : null ?>
                                                    <input type="checkbox" name="runs[<?= $row['run_id'] ?>][locked]" value="<?= $row['locked'] ?>" <?= $checked ?> />
                                                </td>
                                                <td><a href="<?= site_url('superadmin/runs_management?id='.$row['run_id']); ?>" class="btn btn-default"><i class="fa fa-th-list"></i> See Queue</a></td>
                                            </tr>
                                        <?php endwhile; ?>
                                        <tr>
                                            <td colspan="7">
                                                <button type="submit" class="btn btn-primary pull-right"><i class="fa fa-save"></i> Save Changes</button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </form>
                        <?php endif; ?>
                        <div class="pagination">
                            <?php $pagination->render("superadmin/runs_management"); ?>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div class="clear clearfix"></div>
    </section>
    <!-- /.content -->
</div>

<?php Template::loadChild('admin/footer'); ?>