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
                        <h3 class="box-title">Push Message Log </h3>
                    </div>
                    <div class="box-body table-responsive">
                        <?php if ($messages): ?>
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Message</th>
                                        <th>Topic</th>
                                        <th>Status</th>
                                        <th>Error</th>
                                        <th>Position</th>
                                        <th>Attempt</th>
                                        <th>Date and time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($messages as $message): ?>
                                    <tr>
                                        <td>
                                            <?= h($message['message']) ?>
                                            <?php if ($message['template_message'] != $message['message']): ?>
                                                <br><small class="text-muted">Template: <?= h($message['template_message']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h($message['topic']) ?></td>
                                        <td>
                                            <?php 
                                            $label_class = "label-default";
                                            $icon = 'fa-check-circle';
                                            if($message['status'] === 'failed') {
                                                $label_class = "label-danger";
                                                $icon = 'fa-times-circle';
                                            } else if ($message['status'] === 'pending') {
                                                $label_class = "label-info";
                                                $icon = 'fa-hourglass-half';
                                            } else if ($message['status'] === 'sent') {
                                                $label_class = "label-success";
                                                $icon = 'fa-check-circle';
                                            }
                                            ?>
                                            <small class='label <?= $label_class ?> hastooltip' title='<?= h($message['error_message']) ?>'>
                                                <i class='fa <?= $icon ?>'></i> <?= h($message['status']) ?>
                                            </small>
                                        </td>
                                        <td><?= h($message['error_message']) ?></td>
                                        <td><?= $message['position_in_run'] ?></td>
                                        <td><?= $message['attempt'] ?></td>
                                        <td>
                                            <abbr title="<?= $message['created_at']?>">
                                                <?= timetostr(strtotime($message['created_at'])) ?>
                                            </abbr>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="pagination">
                                <?php $pagination->render("admin/run/{$run->name}/push_message_log"); ?>
                            </div>
                        <?php else: ?>
                            <h5 class="lead"><i>No Push Messages yet</i></h5>
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