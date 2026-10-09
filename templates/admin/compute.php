<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper" id="compute-usage-page">
    <section class="content-header">
        <h1>Compute Usage <small>Runtime of your studies</small></h1>
    </section>

    <section class="content">
        <?php Template::loadChild('public/alerts'); ?>

        <?php Template::loadChild('admin/parts/compute_totals'); ?>

        <div class="row">
            <div class="col-md-12">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Compute per run</h3>
                    </div>
                    <div class="box-body table-responsive">
                        <?php if (!empty($runs)): ?>
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Run</th>
                                        <th class="text-right">Unit sessions</th>
                                        <th class="text-right">Total time</th>
                                        <th class="text-right">Avg / session</th>
                                        <th class="text-right">Slowest session</th>
                                        <th class="text-right">Last activity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($runs as $r): ?>
                                    <tr>
                                        <td><a href="<?= admin_run_url($r['run_name']) ?>"><?= h($r['run_name']) ?></a></td>
                                        <td class="text-right"><?= number_format((int)$r['n_sessions']) ?></td>
                                        <td class="text-right"><?= h(ComputeUsageHelper::formatDuration($r['total_time'])) ?></td>
                                        <td class="text-right"><?= h(ComputeUsageHelper::formatDuration($r['avg_time'])) ?></td>
                                        <td class="text-right"><?= h(ComputeUsageHelper::formatDuration($r['max_time'])) ?></td>
                                        <td class="text-right"><?= $r['last_activity'] ? h(timetostr(strtotime((string)$r['last_activity']))) : '&ndash;' ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p class="text-muted">No compute has been recorded yet. Runtime is measured per unit session from the moment this feature was enabled, so this table fills in as participants (or test sessions) move through your runs.</p>
                        <?php endif; ?>
                    </div>
                    <div class="box-footer text-muted">
                        Time is wall-clock seconds spent executing each unit, including calls to OpenCPU/R. Repeated visits to a unit (surveys, pauses) accumulate.
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php Template::loadChild('admin/footer'); ?>
