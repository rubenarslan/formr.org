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
                        <h3 class="box-title"><b><?php echo $run->name; ?></b> Sessions Queue</h3>
                    </div>
                    <div class="box-body">
                        <?php Template::loadChild('public/alerts'); ?>
                        <p class="lead"> This shows the list of sessions in run waiting to be processed.</p>
                        
                        <div class="table-responsive">
                            <table class="table table-striped has-actions">
                                <thead>
                                    <tr>
                                        <th>Session</th>
                                        <th>Unit (position)</th>
                                        <th>Added On</th>
                                        <th>Expires</th>
                                        <th>To Execute</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($row = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                                    <tr>
                                        <td>
                                            <a class="btn hastooltip" href="<?php echo admin_run_url($run->name, "user_detail?session=" . urlencode(substr($row['session'], 0, 15))); ?>" title="Go to user detail"><i class="fa fa-list"></i></a>
                                            <?php 
                                                $animal_end = strpos($row['session'], "XXX");
                                                if ($animal_end === false) {
                                                    $animal_end = 10;
                                                }
                                                $short_session = substr($row['session'], 0, $animal_end);
                                            ?>
                                            <small><abbr class="abbreviated_session" title="Click to show the full session" data-full-session="<?php echo $row['session']; ?>"><?php echo $short_session ?>…</abbr></small>
                                        </td>
                                        <td><?= $row['unit_type'] ?> (<?=$row['position']?>)</td>
                                        <td><?php echo $row['created'] ?></td>
                                        <td><?php echo $row['expires'] ?></td>
                                        <td>
                                            <?php echo $row['queued'] == UnitSessionQueue::QUEUED_TO_EXECUTE ? '<span class="label label-success">YES</span>' : '<span class="label label-default">NO</span>'; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
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