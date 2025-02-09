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
                                        <th>Status</th>
                                        <th>Error</th>
                                        <th>Attempt</th>
                                        <th>Date and time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($messages as $message): ?>
                                    <tr>
                                        <td><?= h($message['message']) ?></td>
                                        <td>
                                            <?php 
                                            $label_class = "label-default";
                                            $icon = 'fa-check-circle';
                                            if($message['status'] === 'failed') {
                                                $label_class = "label-danger";
                                                $icon = 'fa-times-circle';
                                            }
                                            ?>
                                            <small class='label <?= $label_class ?> hastooltip' title='<?= h($message['error_message']) ?>'>
                                                <i class='fa <?= $icon ?>'></i> <?= h($message['status']) ?>
                                            </small>
                                        </td>
                                        <td><?= h($message['error_message']) ?></td>
                                        <td><?= $message['attempt'] ?></td>
                                        <td>
                                            <abbr title="<?= $message['created']?>">
                                                <?= timetostr(strtotime($message['created'])) ?>
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