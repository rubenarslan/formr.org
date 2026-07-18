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
                                            <th class="text-right">No. Sessions</th>
                                            <th class="text-center">Public</th>
                                            <th class="text-center">Cron Active</th>
                                            <th class="text-center">Locked</th>
                                            <th>Sessions Queue</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        // publicness → icon + tooltip (levels mirror the run index page)
                                        $publicIcons = array(
                                            0 => array('fa-lock',  'text-muted',   'Private — only you and test users can access'),
                                            1 => array('fa-key',   'text-warning', 'Access-code holders only (no new enrolment)'),
                                            2 => array('fa-link',   '',            'Anyone with the link can access'),
                                            3 => array('fa-globe',  'text-success','Fully public — listed on the studies page'),
                                        );
                                        ?>
                                        <?php while ($row = $pdoStatement->fetch(PDO::FETCH_ASSOC)): ?>
                                            <?php // hard stop: badge only while the run is still fully closed — the
                                                  // marker is a permanent audit trail, but once the owner republishes
                                                  // or re-enables cron the run is no longer compute-paused
                                                  $paused = $row['compute_closed_from'] !== null
                                                      && (int) $row['public'] === 0 && (int) $row['cron_active'] === 0;
                                                  $pi = $publicIcons[(int) $row['public']] ?? $publicIcons[0]; ?>
                                            <tr<?= $paused ? ' class="warning"' : '' ?>>
                                                <td><?= $row['run_id'] ?></td>
                                                <td><?= $row['name'] ?><?php if ($paused): ?> <span class="label label-warning hastooltip" title="Auto-paused by the monthly compute limiter (non-public + cron off); NOT reopened automatically — the owner must republish and re-enable cron"><i class="fa fa-pause"></i> compute-paused</span><?php endif; ?></td>
                                                <td><?= $row['email'] ?></td>
                                                <td class="text-right"><?= $row['sessions'] ?></td>
                                                <td class="text-center"><i class="fa <?= $pi[0] ?> <?= $pi[1] ?> hastooltip" title="<?= h($pi[2]) ?>"></i></td>
                                                <td class="text-center">
                                                    <input type="hidden" name="runs[<?= $row['run_id'] ?>][run]" value="<?= $row['run_id'] ?>" />
                                                    <?php $checked = $row['cron_active'] ? 'checked="checked"' : null ?>
                                                    <input type="checkbox" name="runs[<?= $row['run_id'] ?>][cron_active]" value="<?= $row['cron_active'] ?>" <?= $checked ?> />
                                                </td>
                                                <td class="text-center">
                                                    <?php $checked = $row['locked'] ? 'checked="checked"' : null ?>
                                                    <input type="checkbox" name="runs[<?= $row['run_id'] ?>][locked]" value="<?= $row['locked'] ?>" <?= $checked ?> />
                                                </td>
                                                <td><a href="<?= site_url('admin/advanced/runs_management?id='.$row['run_id']); ?>" class="btn btn-default"><i class="fa fa-th-list"></i> See Queue</a></td>
                                            </tr>
                                        <?php endwhile; ?>
                                        <tr>
                                            <td colspan="8">
                                                <button type="submit" class="btn btn-primary pull-right"><i class="fa fa-save"></i> Save Changes</button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </form>
                        <?php endif; ?>
                        <div class="pagination">
                            <?php $pagination->render("admin/advanced/runs_management"); ?>
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