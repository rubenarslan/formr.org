<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper" id="compute-usage-superadmin-page">
    <section class="content-header">
        <h1>Compute Usage <small>Superadmin &mdash; whole instance</small></h1>
    </section>

    <section class="content">
        <?php Template::loadChild('public/alerts'); ?>

        <div class="row">
            <div class="col-md-4 col-sm-6">
                <div class="info-box">
                    <span class="info-box-icon bg-aqua"><i class="fa fa-clock-o"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total compute</span>
                        <span class="info-box-number"><?= h(ComputeUsageHelper::formatDuration($totals['total_time'] ?? 0)) ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div class="info-box">
                    <span class="info-box-icon bg-green"><i class="fa fa-calendar"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">This month</span>
                        <span class="info-box-number"><?= h(ComputeUsageHelper::formatDuration($totals['month_time'] ?? 0)) ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div class="info-box">
                    <span class="info-box-icon bg-yellow"><i class="fa fa-tasks"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Unit sessions measured</span>
                        <span class="info-box-number"><?= number_format((int)($totals['n_sessions'] ?? 0)) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Compute by user</h3>
                        <span class="pull-right text-muted">instance default limit: <?= h(ComputeUsageHelper::formatLimit($monthly_default)) ?> / month</span>
                    </div>
                    <div class="box-body table-responsive">
                        <p class="text-muted">A user's <strong>monthly limit</strong> is their compute budget across all their runs. When this month's usage reaches it, their public runs are set non-public until usage drops back under the limit (e.g. next month). Leave the field blank to inherit the instance default; enter seconds, or <code>0</code> for unlimited. Users cannot change their own limit.</p>
                        <?php if (!empty($users)): ?>
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th class="text-right">Runs</th>
                                        <th class="text-right">Unit sessions</th>
                                        <th class="text-right">This month</th>
                                        <th class="text-right">Total time</th>
                                        <th class="text-right">Effective limit</th>
                                        <th>Monthly limit (s)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $u): ?>
                                    <?php $over = $monthly_default || $u['compute_limit_monthly'] !== null;
                                          $eff = ComputeUsageHelper::effectiveLimit($u['compute_limit_monthly']);
                                          $tripped = $eff > 0 && (float)$u['month_time'] >= $eff; ?>
                                    <tr<?= $tripped ? ' class="danger"' : '' ?>>
                                        <td><?= h($u['email']) ?><?php if (!empty($u['paused_runs'])): ?> <span class="label label-danger hastooltip" title="Runs auto-paused by the monthly compute limiter (non-public + cron paused) until usage drops back under the limit"><i class="fa fa-pause"></i> <?= (int)$u['paused_runs'] ?> run<?= (int)$u['paused_runs'] === 1 ? '' : 's' ?> paused</span><?php endif; ?></td>
                                        <td class="text-right"><?= number_format((int)$u['n_runs']) ?></td>
                                        <td class="text-right"><?= number_format((int)$u['n_sessions']) ?></td>
                                        <td class="text-right"><?= h(ComputeUsageHelper::formatDuration($u['month_time'])) ?></td>
                                        <td class="text-right"><?= h(ComputeUsageHelper::formatDuration($u['total_time'])) ?></td>
                                        <td class="text-right"><?= h(ComputeUsageHelper::formatLimit($eff)) ?><?= $u['compute_limit_monthly'] === null ? ' <small class="text-muted">(default)</small>' : '' ?></td>
                                        <td>
                                            <form action="<?= site_url('admin/advanced/compute_usage') ?>" method="post" class="form-inline">
                                                <input type="hidden" name="set_compute_limit_user_id" value="<?= (int)$u['id'] ?>">
                                                <input type="number" min="0" step="any" name="compute_limit_monthly" class="form-control input-sm" style="width:120px" value="<?= $u['compute_limit_monthly'] === null ? '' : h(rtrim(rtrim(number_format((float)$u['compute_limit_monthly'], 3, '.', ''), '0'), '.')) ?>" placeholder="default">
                                                <button type="submit" class="btn btn-default btn-sm">Save</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p class="text-muted">No compute has been recorded yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Heaviest runs</h3>
                        <span class="pull-right text-muted">top <?= count($runs) ?> by total compute</span>
                    </div>
                    <div class="box-body table-responsive">
                        <?php if (!empty($runs)): ?>
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Run</th>
                                        <th>Owner</th>
                                        <th class="text-right">Unit sessions</th>
                                        <th class="text-right">Avg / session</th>
                                        <th class="text-right">Total time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($runs as $r): ?>
                                    <tr>
                                        <td><a href="<?= admin_run_url($r['run_name']) ?>"><?= h($r['run_name']) ?></a></td>
                                        <td><?= h($r['owner_email']) ?></td>
                                        <td class="text-right"><?= number_format((int)$r['n_sessions']) ?></td>
                                        <td class="text-right"><?= h(ComputeUsageHelper::formatDuration($r['avg_time'])) ?></td>
                                        <td class="text-right"><?= h(ComputeUsageHelper::formatDuration($r['total_time'])) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p class="text-muted">No compute has been recorded yet.</p>
                        <?php endif; ?>
                    </div>
                    <div class="box-footer text-muted">
                        Wall-clock seconds per unit-session execution, including OpenCPU/R calls. Recorded since issue&nbsp;#608 shipped; repeated unit visits accumulate.
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php Template::loadChild('admin/footer'); ?>
