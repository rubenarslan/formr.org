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
                                        <th>Session</th>
                                        <th>Position</th>
                                        <th>Message</th>
                                        <th>Status</th>
                                        <th>Error</th>
                                        <th>Attempt</th>
                                        <th>Date and time</th>
                                        <th>User Detail</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($messages as $message): ?>
                                    <tr>
                                        <td>
                                            <?php if ($currentUser->user_code == $message['session']): ?>
                                                <i class="fa fa-user-md" class="hastooltip" title="This is you"></i>
                                                <?php
                                            endif;

                                            $animal_end = strpos($message['session'], "XXX");
                                            if ($animal_end === false) {
                                                $animal_end = 10;
                                            }
                                            $short_session = substr($message['session'], 0, $animal_end);
                                            ?>
                                            <small><abbr class="abbreviated_session" title="Click to show the full session" data-full-session="<?php echo $message['session']; ?>"><?php echo $short_session ?>…</abbr></small>
                                        </td>
                                        <td><?= h($message['position_in_run']) ?></td>
                                        <td><?= h($message['message']) ?></td>
                                        <td>
                                            <?php 
                                            $label_class = "label-success";
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
                                        <td>
                                            <a href="<?php echo admin_run_url($run->name, "user_detail?session=" . urlencode(substr($message['session'], 0, 15))); ?>" title="Go to user detail"><i class="fa fa-list"></i></a>
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