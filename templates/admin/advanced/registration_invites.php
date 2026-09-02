<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper">
    <section class="content-header">
        <h1>Registration Invites <small>Superadmin</small></h1>
    </section>

    <section class="content">
        <?php Template::loadChild('public/alerts'); ?>

        <p>A single-use invitation token lets one person register past the
            <code>registration.mode</code> gate: it is the ticket in <code>invite</code> mode and a
            per-person <strong>exception to the domain/TLD restriction</strong> in <code>domains</code> mode
            (current mode: <code><?= h($mode) ?></code>). The invitee enters it in the token field of the
            signup form and registers normally — own password, verified email.</p>

        <?php if (!empty($issued)): ?>
            <div class="box box-success">
                <div class="box-header with-border"><h3 class="box-title">New tokens — copy them now, they cannot be shown again</h3></div>
                <div class="box-body">
                    <?php foreach ($issued as $token): ?>
                        <p><code style="font-size: 1.2em;"><?= h($token) ?></code></p>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="row"><div class="col-md-5">
            <div class="box box-primary">
                <div class="box-header with-border"><h3 class="box-title">Issue invites</h3></div>
                <div class="box-body">
                    <form method="post" action="">
                        <input type="hidden" name="do" value="issue">
                        <div class="form-group">
                            <label for="inv_count">How many</label>
                            <input class="form-control" type="number" id="inv_count" name="count" value="1" min="1" max="50">
                        </div>
                        <div class="form-group">
                            <label for="inv_expires">Expires in (days, 0 = never)</label>
                            <input class="form-control" type="number" id="inv_expires" name="expires_days" value="30" min="0">
                        </div>
                        <button type="submit" class="btn btn-primary">Issue</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-7">
            <div class="box box-primary">
                <div class="box-header with-border"><h3 class="box-title">Issued invites</h3></div>
                <div class="box-body table-responsive">
                    <?php if (!empty($invites)): ?>
                        <table class="table table-striped">
                            <thead><tr><th>id</th><th>Issued by</th><th>Created</th><th>Expires</th><th>Used</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($invites as $row): ?>
                                <tr>
                                    <td><?= (int) $row['id'] ?></td>
                                    <td><?= h($row['created_by']) ?></td>
                                    <td><?= h($row['created']) ?></td>
                                    <td><?= h($row['expires'] ?: 'never') ?></td>
                                    <td><?= $row['used_at'] ? h($row['used_at']) . ' (user ' . (int) $row['used_by'] . ')' : 'no' ?></td>
                                    <td>
                                        <?php if (!$row['used_at']): ?>
                                            <form method="post" action="" style="display:inline">
                                                <input type="hidden" name="do" value="revoke">
                                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                                <button type="submit" class="btn btn-xs btn-danger">Revoke</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No invites issued yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div></div>
    </section>
</div>

<?php Template::loadChild('admin/footer'); ?>
