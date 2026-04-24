<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper">

    <section class="content-header">
        <h1>form_v2 compatibility scan <small><?= htmlspecialchars($study->name) ?> (ID: <?= (int) $study->id ?>)</small></h1>
    </section>

    <section class="content">
        <div class="row">
            <div class="col-md-2">
                <?php Template::loadChild('admin/survey/menu'); ?>
            </div>

            <div class="col-md-10">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Upgrade readiness report</h3>
                    </div>

                    <div class="box-body">
                        <?php Template::loadChild('public/alerts'); ?>
                        <div class="callout callout-info">
                            <p>
                                This report classifies every <code>showif</code> and <code>value</code> expression in your survey into one of four buckets. It's the same heuristic used by <code>bin/form_v2_compat_scan.php</code> (CI-friendly; exits non-zero if anything is flagged).
                            </p>
                            <ul>
                                <li><strong>empty</strong> — no expression.</li>
                                <li><strong>r(...) wrapped</strong> — already opted into the server-evaluated path; runs via <code>/form-r-call</code> (showif) or <code>/form-fill</code> (value). No admin action needed.</li>
                                <li><strong>JS-transpile OK</strong> — the v1 regex transpile produces something that looks like valid JS with no residual R-only tokens. Evaluated client-side.</li>
                                <li><strong>needs r(...) wrap</strong> — residual R tokens the client evaluator can't handle. Wrap the source in <code>r(...)</code> so it goes through the server path. The client has a runtime try/catch that falls back to "visible", so a flagged item isn't necessarily broken — but the behaviour is no longer guaranteed.</li>
                            </ul>
                        </div>

                        <h4>Summary</h4>
                        <table class="table table-bordered table-condensed">
                            <thead>
                                <tr>
                                    <th>Column</th>
                                    <th>Empty</th>
                                    <th>r(...) wrapped</th>
                                    <th>JS-transpile OK</th>
                                    <th>Needs r() wrap</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (['showif', 'value'] as $col): $c = $report['counts'][$col]; ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($col) ?></code></td>
                                        <td><?= (int) $c['empty'] ?></td>
                                        <td><?= (int) $c['r_wrapped'] ?></td>
                                        <td><?= (int) $c['js_ok'] ?></td>
                                        <td<?= ((int) $c['needs_wrap'] > 0) ? ' class="text-red"' : '' ?>><strong><?= (int) $c['needs_wrap'] ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php if (!empty($report['flagged'])): ?>
                            <h4 class="text-red">Flagged items (likely need <code>r(...)</code> wrapping)</h4>
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Item</th>
                                        <th>Column</th>
                                        <th>Problems</th>
                                        <th>Source</th>
                                        <th>Transpiled</th>
                                        <th>Suggested</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report['flagged'] as $r): ?>
                                        <tr>
                                            <td><?= (int) $r['id'] ?></td>
                                            <td><code><?= htmlspecialchars($r['name']) ?></code> <small>(<?= htmlspecialchars($r['type']) ?>)</small></td>
                                            <td><code><?= htmlspecialchars($r['column']) ?></code></td>
                                            <td><?= htmlspecialchars(implode(', ', $r['problems'])) ?></td>
                                            <td><code><?= htmlspecialchars($r['source']) ?></code></td>
                                            <td><?php if ($r['transpiled'] !== $r['source']): ?><code><?= htmlspecialchars($r['transpiled']) ?></code><?php else: ?><em class="text-muted">(same as source)</em><?php endif; ?></td>
                                            <td><code>r(<?= htmlspecialchars($r['source']) ?>)</code></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="callout callout-success">
                                <p><i class="fa fa-check"></i> No problematic expressions found. This study looks ready for form_v2.</p>
                            </div>
                        <?php endif; ?>

                        <p class="text-muted"><small>
                            Scan is informational only — it does not mutate <code>survey_items.showif</code> or <code>survey_items.value</code>.
                            Auto-wrap via <code>--auto-wrap</code> is still a CLI TODO.
                        </small></p>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php Template::loadChild('admin/footer'); ?>
