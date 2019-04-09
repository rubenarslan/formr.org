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
            <div class="col-md-10">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Email Log </h3>
                    </div>
                    <div class="box-body table-responsive">
                        <?php if ($emails): ?>

                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>From</th>
                                        <th>To</th>
                                        <th>Mail</th>
                                        <th>Datetime</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($emails as $email): ?>
                                    <tr>
                                        <td><?= $email['from_name'] ?> <br><small><?= $email['from'] ?></small></td>
                                        <td><?= $email['to']?> <br><small> at run position <?= $email['position_in_run'] ?></small></td>
                                        <td><?= $email['subject'] ?> <br><small><?= h(substr($email['body'], 0, 100)) ?>…</small></td>
                                        <td>
                                            <abbr title="<?= $email['created']?>"> <?= timetostr(strtotime($email['created'])) ?></abbr>
                                            <?php echo $email['sent'] ? '<i class="fa fa-check-circle"></i>' : '<i class="fa fa-times-circle"></i>'; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="pagination">
                                <?php $pagination->render("admin/run/{$run->name}/email_log"); ?>
                            </div>
                        <?php else: ?>
                            <h5 class="lead"><i>No E-mails yet</i></h5>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

        <div class="clear clearfix"></div>
    </section>
    <!-- /.content -->
</div>

<?php Template::load('admin/footer'); ?>