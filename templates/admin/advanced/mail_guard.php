<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper">
    <section class="content-header">
        <h1>Outbound Mail Guard <small>Superadmin</small></h1>
    </section>

    <section class="content">
        <?php if (!empty($guard_error)): ?>
            <div class="alert alert-warning">Mail-guard tables are not available yet (has migration 076 run?): <?= h($guard_error) ?></div>
        <?php endif; ?>

        <?php if (!empty($ceiling_tripped)): ?>
            <div class="alert alert-danger">
                <strong>The instance-wide transactional mail ceiling was hit in the last <?= (int) $hours ?> hours.</strong>
                Sends of verification / password-reset mail were refused. If this was not caused by you,
                someone is likely abusing the signup or password-reset form — consider restricting
                <code>registration.mode</code> and adding the targeted addresses to the suppression list
                (<code>php bin/suppression.php</code>). If it was legitimate load, raise
                <code>mail_guard.global_limit_per_hour</code> in <code>config/settings.php</code>.
            </div>
        <?php endif; ?>

        <div class="row"><div class="col-md-6">
            <div class="box box-primary">
                <div class="box-header with-border"><h3 class="box-title">Sends and refusals, last <?= (int) $hours ?> hours</h3></div>
                <div class="box-body table-responsive">
                    <?php if (!empty($summary)): ?>
                        <table class="table table-striped">
                            <thead><tr><th>Category</th><th>Outcome</th><th>Count</th></tr></thead>
                            <tbody>
                            <?php foreach ($summary as $row): ?>
                                <tr><td><?= h($row['category']) ?></td><td><?= h($row['outcome']) ?></td><td><?= (int) $row['n'] ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No outbound mail recorded in this window.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="box box-primary">
                <div class="box-header with-border"><h3 class="box-title">Suppression list</h3></div>
                <div class="box-body table-responsive">
                    <?php if (!empty($suppressions)): ?>
                        <table class="table table-striped">
                            <thead><tr><th>Address</th><th>Reason</th><th>Added by</th><th>Added</th></tr></thead>
                            <tbody>
                            <?php foreach ($suppressions as $row): ?>
                                <tr><td><?= h($row['address']) ?></td><td><?= h($row['reason']) ?></td><td><?= h($row['added_by']) ?></td><td><?= h($row['created']) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>Empty. Add entries with <code>php bin/suppression.php -a add -e address@example.com -r "complained"</code>.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div></div>

        <div class="row"><div class="col-md-12">
            <div class="box box-primary">
                <div class="box-header with-border"><h3 class="box-title">Most recent refusals</h3></div>
                <div class="box-body table-responsive">
                    <?php if (!empty($recent_refusals)): ?>
                        <table class="table table-striped">
                            <thead><tr><th>When</th><th>Recipient</th><th>Type</th><th>Category</th><th>IP</th><th>Outcome</th></tr></thead>
                            <tbody>
                            <?php foreach ($recent_refusals as $row): ?>
                                <tr><td><?= h($row['created']) ?></td><td><?= h($row['recipient']) ?></td><td><?= h($row['type']) ?></td>
                                    <td><?= h($row['category']) ?></td><td><?= h($row['ip']) ?></td><td><?= h($row['outcome']) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No refusals recorded. That is what it should look like.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div></div>
    </section>
</div>

<?php Template::loadChild('admin/footer'); ?>
