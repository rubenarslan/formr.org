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
                        <h3 class="box-title">Email Log </h3>
                    </div>
                    <div class="box-body table-responsive">
                        <?php if ($emails): ?>

                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>From</th>
                                        <th>To</th>
                                        <th>Subject</th>
                                        <th>Status</th>
                                        <th>Date and time (queued/attempted to send)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($emails as $email): ?>
                                    <tr>
                                        <td><?= $email['from_name'] ?> <br><small><?= $email['from'] ?></small></td>
                                        <td><?= $email['to']?> <br><small> at run position <?= $email['position_in_run'] ?></small></td>
                                        <td><?= $email['subject'] ?></td>
                                        <td>
                                            <?php 
                                            $label_class = "label-default";
                                            $icon = 'fa-check-circle';
                                            $text = $email['result'];
                                            $resultlog = $email['result_log'];
                                            if($email['status'] < 0) {
                                                $label_class = "label-danger";
                                                $icon = 'fa-times-circle';
                                            } else if ($email['status'] == 0) {
                                                $label_class = "label-info";
                                                $icon = 'fa-hourglass-half';
                                            } else if ($email['status'] == 1) {
                                                $label_class = "label-success";
                                                $icon = 'fa-check-circle';
                                            }
                                            echo "<small class='label $label_class hastooltip' title='$resultlog'><i class='fa $icon'></i> $text</small>";
                                            ?>
                                        </td>
                                        <td>
                                            q. <abbr title="<?= $email['created']?>"> <?= timetostr(strtotime($email['created'])) ?></abbr><br>
                                            s. <abbr title="<?= $email['sent']?>"> <?= $email['sent'] ? timetostr(strtotime($email['sent'])) : null ?></abbr>
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

<?php Template::loadChild('admin/footer'); ?>