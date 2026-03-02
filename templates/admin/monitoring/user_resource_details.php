<?php

Template::loadChild('admin/header');

$metrics = $metrics ?? null;
$user = $user ?? null;
$surveySizes = $surveySizes ?? [];
?>

<div class="content-wrapper">
    <section class="content-header">
        <h1>User Resource Details <small>Superadmin</small></h1>
        <ol class="breadcrumb">
            <li><a href="<?= site_url('admin/monitoring/survey-resource-monitoring') ?>"><i class="fa fa-bar-chart"></i> Survey Resource Monitoring</a></li>
            <li class="active">User <?= h(isset($user['email']) ? $user['email'] : (isset($user['id']) ? $user['id'] : ($metrics['user_id'] ?? 'Unknown'))) ?></li>
        </ol>
    </section>

    <section class="content">
        <div class="row">
            <div class="col-md-12">
                <?php Template::loadChild('public/alerts'); ?>
                <?php if ($metrics): ?>
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Resource Metrics</h3>
                        <div class="box-tools pull-right">
                            <span class="text-muted">User: <?= h(isset($user['email']) ? $user['email'] : 'ID ' . $metrics['user_id']) ?></span>
                        </div>
                    </div>
                    <div class="box-body">
                        <dl class="dl-horizontal">
                            <dt>Survey count</dt>
                            <dd><?= (int) $metrics['survey_count'] ?></dd>
                            <dt>Survey items size (KB)</dt>
                            <dd><?= number_format((float) $metrics['survey_items_size_kb'], 2) ?></dd>
                            <dt>Run count</dt>
                            <dd><?= (int) $metrics['run_count'] ?></dd>
                            <dt>Uploaded files size (KB)</dt>
                            <dd><?= number_format((float) $metrics['uploaded_files_size_kb'], 2) ?></dd>
                            <dt>Unit sessions count</dt>
                            <dd><?= (int) $metrics['unit_sessions_count'] ?></dd>
                            <dt>Last computed</dt>
                            <dd><?= h($metrics['last_computed_at'] ?? '-') ?></dd>
                            <dt>Last run activity</dt>
                            <dd><?= h($metrics['last_run_activity_at'] ?? '-') ?></dd>
                        </dl>
                    </div>
                </div>

                <div class="box box-default">
                    <div class="box-header with-border">
                        <h3 class="box-title">Survey Sizes (<?= count($surveySizes) ?> studies)</h3>
                    </div>
                    <div class="box-body table-responsive">
                        <?php if (!empty($surveySizes)): ?>
                        <table class="table table-striped table-bordered">
                            <thead>
                                <tr>
                                    <th>Study</th>
                                    <th>Items Size (KB)</th>
                                    <th>Computed At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($surveySizes as $s): ?>
                                <tr>
                                    <td><?= h($s['study_name'] ?? 'Study #' . $s['study_id']) ?></td>
                                    <td><?= number_format((float) $s['items_size_kb'], 2) ?></td>
                                    <td><?= h($s['computed_at'] ?? '-') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <p class="text-muted">No survey sizes recorded.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-warning">No metrics found for this user.</div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

<?php Template::loadChild('admin/footer'); ?>
