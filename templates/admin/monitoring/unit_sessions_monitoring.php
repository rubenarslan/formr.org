<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper">
    <section class="content-header">
        <h1>Unit Sessions Monitoring <small>Superadmin</small></h1>
    </section>

    <section class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Unit Execution Times (longest first)</h3>
                    </div>
                    <div class="box-body table-responsive">
                        <?php Template::loadChild('public/alerts'); ?>
                        <?php if (!empty($logs)): ?>
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Execution Time (ms)</th>
                                        <th>Unit Type</th>
                                        <th>Run</th>
                                        <th>Unit Session ID</th>
                                        <th>Run Unit ID</th>
                                        <th>Created At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td><strong><?= (int) $log['execution_time_ms'] ?></strong></td>
                                        <td><?= h($log['unit_type'] ?? '-') ?></td>
                                        <td><?= h($log['run_name'] ?? '-') ?></td>
                                        <td><?= (int) $log['unit_session_id'] ?></td>
                                        <td><?= (int) $log['run_unit_id'] ?></td>
                                        <td><?= h($log['created_at'] ?? '-') ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="pagination">
                                <?php $pagination->render("admin/monitoring/unit-sessions-monitoring"); ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted">No unit execution logs found.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php Template::loadChild('admin/footer'); ?>
