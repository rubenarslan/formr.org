<?php Template::loadChild('admin/header'); ?>
<?php
$totals = $report['totals'];
$badge_classes = array('contact' => 'label-danger', 'identity' => 'label-danger', 'address' => 'label-danger', 'ids' => 'label-warning',
    'location' => 'label-warning', 'uploads' => 'label-warning', 'technical' => 'label-default');
$category_badge = function ($category) use ($badge_classes, $category_labels) {
    $class = isset($badge_classes[$category]) ? $badge_classes[$category] : 'label-default';
    $label = isset($category_labels[$category]) ? $category_labels[$category] : $category;
    return '<span class="label ' . $class . '">' . h($label) . '</span>';
};
// Admin run/survey pages resolve names against the *logged-in* user
// (AdminRunController::setRun 401s on anyone else's run), so only the
// superadmin's own runs and surveys get links; others show name + id.
$mine = function ($owner_id) use ($user) { return (int) $owner_id === (int) $user->id; };
$survey_rows = function ($study) use ($category_badge, $mine) {
    $items = array();
    $technical = array();
    foreach ($study['items'] as $item) {
        // ip/browser/referrer/push endpoints are collected by half the runs on
        // an instance; one muted line keeps them from drowning the real hits.
        if ($item['category'] === 'technical') {
            $technical[] = '<code>' . h($item['name']) . '</code>';
            continue;
        }
        $items[] = '<div><code>' . h($item['name']) . '</code> <small class="text-muted">' . h($item['type']) . '</small> '
                . $category_badge($item['category']) . ' <small class="text-muted">' . h($item['reason']) . '</small></div>';
    }
    if ($technical) {
        $items[] = '<div class="text-muted"><small>' . $category_badge('technical') . ' ' . implode(', ', $technical) . '</small></div>';
    }
    $count = $study['real_users'] === null ? '<span class="text-muted">?</span>' : (int) $study['real_users'] . ' <small class="text-muted">(' . (int) $study['finished'] . ' finished)</small>';
    $name = $mine($study['owner_id']) ? '<a href="' . admin_study_url($study['name']) . '">' . h($study['name']) . '</a>' : h($study['name']);
    return '<td data-study-id="' . (int) $study['id'] . '">' . $name . ' <small class="text-muted">#' . (int) $study['id'] . '</small></td>'
            . '<td class="text-right">' . $count . '</td>'
            . '<td>' . implode('', $items) . '</td>';
};
?>
<div class="content-wrapper" id="personal-data-audit">
    <section class="content-header">
        <h1>Personal Data <small>Superadmin</small></h1>
    </section>

    <section class="content">
        <?php if (!empty($audit_error)): ?>
            <div class="alert alert-warning">The audit query failed: <?= h($audit_error) ?></div>
        <?php endif; ?>

        <div class="row">
            <?php foreach (array('admins' => array('Admins', 'fa-users'), 'runs' => array('Runs', 'fa-play'), 'surveys' => array('Surveys', 'fa-table'), 'items' => array('Flagged items', 'fa-user-secret')) as $key => $box): ?>
                <div class="col-md-3 col-sm-6">
                    <div class="info-box"><span class="info-box-icon bg-aqua"><i class="fa <?= $box[1] ?>"></i></span>
                        <div class="info-box-content"><span class="info-box-text"><?= $box[0] ?></span>
                            <span class="info-box-number" data-total="<?= $key ?>"><?= (int) $totals[$key] ?></span>
                            <span class="progress-description">with likely personal data</span></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">What this page can and cannot see</h3></div>
            <div class="box-body">
                <p>An item counts as <em>likely personal data</em> when its <strong>type</strong> collects it by construction
                    (<code>email</code>, <code>tel</code>, <code>request_phone</code>, <code>geopoint</code>, <code>file</code>,
                    <code>ip</code>, <code>browser</code>, <code>server</code>, <code>referrer</code>, <code>push_notification</code>)
                    or when a free-response item's <strong>name or label</strong> mentions a keyword
                    (phone, e-mail, name, date of birth, address, IBAN, student/Prolific/MTurk/SONA IDs, …).
                    Choice items are never flagged. A text item labelled "Tell us about yourself" is invisible to this query,
                    and a "phone" question may be about phone <em>ownership</em> — read the reason column before acting.
                    Keywords live in <code>PersonalDataAudit::KEYWORDS</code>; extend them for your instance's vocabulary.</p>
                <p class="text-muted">
                    <?php foreach ($totals['by_category'] as $category => $n): ?>
                        <?= $category_badge($category) ?> <?= (int) $n ?> &nbsp;
                    <?php endforeach; ?>
                    Participant counts are non-tester sessions (runs) and non-tester results (surveys); surveys in no run are listed under their owner.
                </p>
            </div>
        </div>

        <?php if (empty($report['admins'])): ?>
            <div class="alert alert-success">No item on this instance matched the heuristic.</div>
        <?php else: ?>
            <div class="box box-primary" id="personal-data-admins">
                <div class="box-header with-border"><h3 class="box-title">Per admin</h3></div>
                <div class="box-body table-responsive no-padding">
                    <table class="table table-striped table-condensed">
                        <thead><tr><th>Admin</th><th class="text-right">Level</th><th class="text-right">Runs</th><th class="text-right">Participants</th><th class="text-right">Surveys</th><th class="text-right">Flagged items</th><th>Categories</th></tr></thead>
                        <tbody>
                        <?php foreach ($report['admins'] as $admin): $s = $admin['summary']; ?>
                            <tr>
                                <td><a href="#admin-<?= (int) $admin['id'] ?>"><?= h($admin['email']) ?></a></td>
                                <td class="text-right"><?= (int) $admin['admin'] ?></td>
                                <td class="text-right"><?= (int) $s['runs'] ?></td>
                                <td class="text-right"><?= (int) $s['participants'] ?></td>
                                <td class="text-right"><?= (int) $s['surveys'] ?></td>
                                <td class="text-right"><?= (int) $s['items'] ?></td>
                                <td><?php foreach ($s['by_category'] as $category => $n): ?><?= $category_badge($category) ?> <?= (int) $n ?>&nbsp; <?php endforeach; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($report['admins'] as $admin): ?>
            <div class="box box-primary admin-block" id="admin-<?= (int) $admin['id'] ?>" data-user-id="<?= (int) $admin['id'] ?>">
                <div class="box-header with-border">
                    <h3 class="box-title">
                        <?= h($admin['email']) ?>
                        <small class="text-muted">admin level <?= (int) $admin['admin'] ?> &middot; <?= count($admin['runs']) ?> run(s)<?= $admin['orphan_surveys'] ? ', ' . count($admin['orphan_surveys']) . ' survey(s) in no run' : '' ?></small>
                    </h3>
                </div>
                <div class="box-body table-responsive no-padding">
                    <table class="table table-striped table-condensed">
                        <thead><tr><th>Run</th><th class="text-right">Participants</th><th>Survey</th><th class="text-right">Results</th><th>Flagged items</th></tr></thead>
                        <tbody>
                        <?php foreach ($admin['runs'] as $run): $first = true; ?>
                            <?php foreach ($run['surveys'] as $study): ?>
                                <tr>
                                    <?php if ($first): $first = false; ?>
                                        <td rowspan="<?= count($run['surveys']) ?>" data-run-id="<?= (int) $run['id'] ?>">
                                            <?php if ($mine($admin['id'])): ?><a href="<?= admin_run_url($run['name']) ?>"><?= h($run['name']) ?></a><?php else: ?><?= h($run['name']) ?><?php endif; ?>
                                            <?php $public_labels = array(0 => 'closed', 1 => 'enrolled only', 2 => 'link access', 3 => 'listed publicly'); ?>
                                            <span class="label <?= $run['public'] >= 2 ? 'label-success' : 'label-default' ?>"><?= isset($public_labels[$run['public']]) ? $public_labels[$run['public']] : (int) $run['public'] ?></span>
                                            <?php if ($run['email_units'] > 0): ?><span class="label label-info" title="Email units in this run"><i class="fa fa-envelope"></i> <?= (int) $run['email_units'] ?></span><?php endif; ?>
                                        </td>
                                        <td rowspan="<?= count($run['surveys']) ?>" class="text-right"><?= (int) $run['sessions'] ?></td>
                                    <?php endif; ?>
                                    <?= $survey_rows($study) ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        <?php foreach ($admin['orphan_surveys'] as $study): ?>
                            <tr class="text-muted">
                                <td><em>(not in a run)</em></td><td></td>
                                <?= $survey_rows($study) ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
</div>

<?php Template::loadChild('admin/footer'); ?>
