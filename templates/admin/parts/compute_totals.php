<?php
/**
 * Compute-usage headline totals (three info boxes), shared by the per-user
 * dashboard (admin/compute) and the superadmin instance-wide view
 * (admin/advanced/compute_usage). Reads $totals from the parent view's vars
 * (Template::loadChild merges them). Review 2026-07 cleanup #3.
 */
?>
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
                <span class="info-box-number"><?= number_format((int) ($totals['n_sessions'] ?? 0)) ?></span>
            </div>
        </div>
    </div>
</div>
