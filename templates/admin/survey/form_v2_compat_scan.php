<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper">

    <section class="content-header">
        <h1>v2 compatibility check <small><?= htmlspecialchars($study->name) ?> <?= study_engine_badge($study) ?> (ID: <?= (int) $study->id ?>)</small></h1>
    </section>

    <section class="content">
        <div class="row">
            <div class="col-md-2">
                <?php Template::loadChild('admin/survey/menu'); ?>
            </div>

            <div class="col-md-10">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Is this survey ready for v2?</h3>
                    </div>

                    <div class="box-body">
                        <?php Template::loadChild('public/alerts'); ?>
                        <div class="callout callout-info">
                            <p><strong>What is different in v2.</strong> In v1, a <code>showif</code> is R code that the server runs before it shows a page. In v2, a <code>showif</code> is JavaScript that runs in the participant's browser, so items appear and disappear the moment an answer changes. Many showifs are the same in both, such as <code>age &gt;= 18</code>. Others need small changes:</p>
                            <table class="table table-condensed" style="max-width: 40em; background: transparent;">
                                <thead><tr><th>v1 (R)</th><th>v2 (JavaScript)</th></tr></thead>
                                <tbody>
                                    <tr><td><code>a == 1 &amp; b == 2</code>, <code>a == 1 | b == 2</code></td><td><code>a == 1 &amp;&amp; b == 2</code>, <code>a == 1 || b == 2</code></td></tr>
                                    <tr><td><code>TRUE</code>, <code>FALSE</code></td><td><code>true</code>, <code>false</code></td></tr>
                                    <tr><td><code>is.na(x)</code></td><td><code>isNA(x)</code></td></tr>
                                    <tr><td><code>x %contains% "a"</code></td><td><code>contains(x, "a")</code></td></tr>
                                    <tr><td><code>x %in% c(1, 2)</code></td><td><code>x == 1 || x == 2</code></td></tr>
                                    <tr><td><code>current(x)</code>, <code>tail(x, 1)</code></td><td><code>x</code></td></tr>
                                </tbody>
                            </table>
                            <p>A v2 showif can only use items of this survey, because the browser knows nothing else. To show an item based on another survey or on R code, add a <code>hidden</code> item whose <code>value</code> is that R code, and use the hidden item's name in the showif.</p>
                            <p><code>value</code>s stay R in v2 and still run on the server, so they need no changes. <code>calculate</code> items do not exist in v2: use a <code>hidden</code> item with the same <code>value</code> instead.</p>
                            <p>If you move the survey to v2 anyway, an item whose showif does not work in v2 stays hidden.</p>
                        </div>

                        <?php if (!empty($report['counts']['unsupported'])): ?>
                            <p class="text-red"><strong><?= (int) $report['counts']['unsupported'] ?> item(s) that v2 cannot run</strong>, such as <code>calculate</code> items. Replace them before moving the survey to v2 (see the list below).</p>
                        <?php endif; ?>
                        <?php if (!empty($report['counts']['comma_lists'])): ?>
                            <p class="text-red"><strong><?= (int) $report['counts']['comma_lists'] ?> choice list(s) written in one cell with commas.</strong> v2 separates such a list with semicolons, so each would become a single choice (see the list below).</p>
                        <?php endif; ?>

                        <h4>showifs</h4>
                        <table class="table table-bordered table-condensed">
                            <thead>
                                <tr><th>None</th><th>Work in v2</th><th>Need a JavaScript rewrite</th><th>Use something not in this survey</th></tr>
                            </thead>
                            <tbody>
                                <?php $c = $report['counts']['showif']; ?>
                                <tr>
                                    <td><?= (int) $c['empty'] ?></td>
                                    <td><?= (int) $c['js_ok'] ?></td>
                                    <?php foreach (array('needs_js_rewrite', 'not_on_form') as $bucket): ?>
                                        <td<?= ((int) $c[$bucket] > 0) ? ' class="text-red"' : '' ?>><strong><?= (int) $c[$bucket] ?></strong></td>
                                    <?php endforeach; ?>
                                </tr>
                            </tbody>
                        </table>

                        <h4>values</h4>
                        <table class="table table-bordered table-condensed">
                            <thead>
                                <tr><th>None</th><th>A number</th><th>R code (runs on the server, no change needed)</th></tr>
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

                        <?php
                        // "Upgrade anyway": for when the scan's showif heuristic is
                        // wrong about one, or a one-cell comma list is meant as one
                        // choice. Items v2 cannot run still block it.
                        $fmrShowifFlagged = count(array_filter((array) ($report['flagged'] ?? []), function ($f) {
                            return ($f['column'] ?? '') === 'showif' || ($f['suggested_fix'] ?? '') === 'pack_with_semicolons';
                        }));
                        if ($study->rendering_mode !== 'v2' && Config::get('form_v2_enabled') && $fmrShowifFlagged > 0): ?>
                            <form method="post" action="<?= admin_study_url($study->name, 'switch_rendering_mode') ?>" style="margin-bottom: 1em">
                                <input type="hidden" name="target" value="v2">
                                <input type="hidden" name="force" value="1">
                                <button type="submit" class="btn btn-warning" data-confirm="Move to v2 anyway? Items with a flagged showif will stay hidden in v2 until you fix them, and a flagged one-cell list becomes a single choice. Calculate items still have to be replaced first."><i class="fa fa-exclamation-triangle"></i> Move to v2 anyway</button>
                            </form>
                        <?php endif; ?>
                        <?php if (!empty($report['flagged'])): ?>
                            <h4 class="text-red">What to change</h4>
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Item</th>
                                        <th>Column</th>
                                        <th>Problem</th>
                                        <th>As written</th>
                                        <th>What to do</th>
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
                                            <td>
                                                <?php if ($r['suggested_fix'] === 'bridge_hidden_item'): ?>
                                                    Add a <code>hidden</code> item whose <code>value</code> computes this in R, and use that item's name in the showif.
                                                <?php elseif ($r['suggested_fix'] === 'pack_with_semicolons'): ?>
                                                    If the cell lists several choices, separate them with semicolons (<code>red;green;blue</code>) or give each its own row. If it is one choice, nothing to do.
                                                <?php elseif ($r['suggested_fix'] === 'edit_choices'): ?>
                                                    Remove the semicolons from the choices in this choice list.
                                                <?php elseif ($r['suggested_fix'] === 'rename_item'): ?>
                                                    Rename the item, and the showifs and R that use it: the v2 form uses this name itself.
                                                <?php elseif ($r['suggested_fix'] === 'use_hidden'): ?>
                                                    Use a <code>hidden</code> item with the same <code>value</code> instead. If only feedback or a later unit needs the result, compute it there.
                                                <?php else: ?>
                                                    Rewrite it in JavaScript (see the table above), or put the R into a <code>hidden</code> item's <code>value</code> and use that item's name in the showif.
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="callout callout-success">
                                <p><i class="fa fa-check"></i> Nothing to change. This survey is ready for v2.</p>
                            </div>
                        <?php endif; ?>

                        <p class="text-muted"><small>
                            This check changes nothing in your survey. It can miss problems, so test the survey before participants start.
                        </small></p>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php Template::loadChild('admin/footer'); ?>
