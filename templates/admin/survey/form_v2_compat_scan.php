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
                                This report classifies every <code>showif</code> and <code>value</code> expression in your survey under the form_v2 rules. It's the same heuristic used by <code>bin/form_v2_compat_scan.php</code> (CI-friendly; exits non-zero if anything is flagged).
                            </p>
                            <ul>
                                <li><strong>showif is JS-only.</strong> Evaluated client-side, reactive on every input change.
                                    <ul>
                                        <li><strong>JS-OK</strong> — transpiles cleanly to JS the client evaluator understands.</li>
                                        <li><strong>Needs JS rewrite</strong> — residual R tokens the client can't handle. Rewrite in JS, or move the R into a hidden item's <code>value</code> column and reference the field name from the showif.</li>
                                        <li><strong class="text-red">Invalid: r() in showif</strong> — no longer supported. Migrate to the hidden-field bridge.</li>
                                    </ul>
                                </li>
                                <li><strong>value is R-only.</strong> Any non-empty, non-numeric value is treated as R, allowlisted, and evaluated server-side (page-scoped: first visible page inline at render, later pages on the page transition via <code>/form-render-page</code>).
                                    <ul>
                                        <li><strong>literal</strong> — numeric default; not evaluated.</li>
                                        <li><strong>R</strong> — admin-authored R; recorded in <code>survey_r_calls</code> and resolved server-side.</li>
                                    </ul>
                                </li>
                            </ul>
                        </div>

                        <h4>showif summary</h4>
                        <table class="table table-bordered table-condensed">
                            <thead>
                                <tr><th>Empty</th><th>JS-OK</th><th>Needs JS rewrite</th><th>r() in showif (invalid)</th></tr>
                            </thead>
                            <tbody>
                                <?php $c = $report['counts']['showif']; ?>
                                <tr>
                                    <td><?= (int) $c['empty'] ?></td>
                                    <td><?= (int) $c['js_ok'] ?></td>
                                    <td<?= ((int) $c['needs_js_rewrite'] > 0) ? ' class="text-red"' : '' ?>><strong><?= (int) $c['needs_js_rewrite'] ?></strong></td>
                                    <td<?= ((int) $c['invalid_r'] > 0) ? ' class="text-red"' : '' ?>><strong><?= (int) $c['invalid_r'] ?></strong></td>
                                </tr>
                            </tbody>
                        </table>

                        <h4>value summary</h4>
                        <table class="table table-bordered table-condensed">
                            <thead>
                                <tr><th>Empty</th><th>Literal (numeric)</th><th>R (allowlisted, evaluated)</th></tr>
                            </thead>
                            <tbody>
                                <?php $c = $report['counts']['value']; ?>
                                <tr>
                                    <td><?= (int) $c['empty'] ?></td>
                                    <td><?= (int) $c['literal'] ?></td>
                                    <td><?= (int) $c['r'] ?></td>
                                </tr>
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
                                            <td>
                                                <?php
                                                $fix = $r['suggested_fix'] ?? null;
                                                if ($fix === 'create_hidden_field'):
                                                    $hiddenName = $r['name'] . '_r';
                                                ?>
                                                    Add a hidden item:<br>
                                                    <small><code>name: <?= htmlspecialchars($hiddenName) ?>, type: hidden, value: r(<?= htmlspecialchars($r['source']) ?>)</code></small><br>
                                                    Then change this item's showif to:<br>
                                                    <small><code><?= htmlspecialchars($hiddenName) ?> == ...</code></small>
                                                <?php elseif ($fix === 'wrap_in_r'): ?>
                                                    <code>r(<?= htmlspecialchars($r['source']) ?>)</code>
                                                <?php else: ?>
                                                    Rewrite <code><?= htmlspecialchars($r['source']) ?></code> in JS, or move the R into a hidden field's <code>value</code> and reference its name from the showif.
                                                <?php endif; ?>
                                            </td>
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
