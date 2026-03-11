<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper">
    <section class="content-header">
        <h1>Survey Resource Monitoring <small>Superadmin</small></h1>
    </section>

    <section class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Resource Metrics by User</h3>
                    </div>
                    <div class="box-body table-responsive">
                        <?php Template::loadChild('public/alerts'); ?>
                        <?php if (!empty($metrics)): ?>
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>User ID</th>
                                        <th>Email</th>
                                        <th>Surveys</th>
                                        <th>Survey Items (KB)</th>
                                        <th>Runs</th>
                                        <th>Uploaded Files (KB)</th>
                                        <th>Unit Sessions</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($metrics as $m): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $m['user_id']) ?></td>
                                        <td><?= h($m['email'] ?? '-') ?></td>
                                        <td><?= (int) $m['survey_count'] ?></td>
                                        <td><?= number_format((float) $m['survey_items_size_kb'], 2) ?></td>
                                        <td><?= (int) $m['run_count'] ?></td>
                                        <td><?= number_format((float) $m['uploaded_files_size_kb'], 2) ?></td>
                                        <td><?= (int) $m['unit_sessions_count'] ?></td>
                                        <td>
                                            <a href="<?= site_url('admin/monitoring/user-resource-details', ['user_id' => $m['user_id']]) ?>" class="btn btn-xs btn-info" title="View details">
                                                <i class="fa fa-external-link"></i> More
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="pagination">
                                <?php $pagination->render("admin/monitoring/survey-resource-monitoring"); ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted">No resource metrics found.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php Template::loadChild('admin/footer'); ?>
