<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1>User Management <small>Superadmin</small></h1>
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Formr active users </h3>
                    </div>
                    <div class="box-body table-responsive">
                        <?php if ($pdoStatement->rowCount()): ?>
                            <table class='table table-striped'>
                                <thead>
                                    <tr>
                                        <th>Email</th>
                                        <th>Created</th>
                                        <th>Modified</th>
                                        <th>Run</th>
                                        <th>Users</th>
                                        <th>Last Active</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $prev_user = null; while ($userx = $pdoStatement->fetch(PDO::FETCH_ASSOC)): ?>
                                    <tr>
                                        <td>
                                            <?php if ($prev_user != $userx['id']): $prev_user = $userx['id'] ?>
                                                <a href="mailto:<?= h($userx['email']) ?>"><?= $userx['email'] ?></a>
                                                <?php echo $userx['email_verified'] ? ' <i class="fa fa-check-circle-o"></i>' : ' <i class="fa fa-envelope-o"></i>'; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><small class="hastooltip" title="<?= $userx['created'] ?>"><?= timetostr(strtotime($userx['created'])) ?></small></td>
                                        <td><small class="hastooltip" title="<?= $userx['modified'] ?>"><?= timetostr(strtotime($userx['modified'])) ?></small></td>
                                        <td>
                                            <?php 
                                                echo h($userx['run_name']);
                                                echo $userx['cron_active'] ? ' <i class="fa fa-cog"></i> ' : ' ';
                                                echo '<i class="fa '.$status_icons[(int)$userx['public']].'"></i>';
                                            ?>
                                        </td>
                                        <td><?= $userx['number_of_users_in_run'] ?></td>
                                        <td><small class="hastooltip" title="<?= $userx['last_edit'] ?>"><?= timetostr(strtotime($userx['last_edit'])) ?></small></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                            <div class="pagination">
                                <?php $pagination->render("admin/advanced/active_users"); ?>
                            </div>

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