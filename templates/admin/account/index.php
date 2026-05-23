<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper">
    <section class="content-header">
        <h1>User Profile </h1>
    </section>

    <section class="content">

        <div class="row">
            <div class="col-md-3">
                <?php if (!$user->isAdmin()): ?>
                    <div class="box box-warning text-center" style="background-color: #f39c12; color: #fff; padding: 25px;">
                        <div class="box-header">
                            <i class="fa fa-warning fa-2x" style="font-size: 55px; color: #fff"></i>
                        </div>
                        <div class="box-body box-profile">
                            <h3>Your account is limited. You can request for full access as specified in the documentation</h3>
                            <a href="<?= site_url('documentation/#get_started') ?>" class="btn btn-default" target="_blank"><i class="fa fa-link"></i> See Documentation</a>
                        </div>
                        <!-- /.box-body -->
                    </div>
                <?php endif; ?>

                <div class="box box-primary">
                    <div class="box-body box-profile">
                        <div class="text-center">
                            <i class="fa fa-user fa-5x"></i>
                        </div>

                        <h3 class="profile-username text-center"><?= h($names) ?></h3>

                        <p class="text-muted text-center"><?= h($affiliation) ?></p>

                        <ul class="list-group list-group-unbordered">
                            <li class="list-group-item">
                                <b>Surveys</b> <a class="pull-right" href="<?= admin_url('survey/list'); ?>"><?= $survey_count ?></a>
                            </li>
                            <li class="list-group-item">
                                <b>Runs(Studies)</b> <a class="pull-right" href="<?= admin_url('run/list'); ?>"><?= $run_count ?></a>
                            </li>
                            <li class="list-group-item">
                                <b>Email Accounts</b> <a class="pull-right" href="<?= admin_url('mail'); ?>"><?= $mail_count ?></a>
                            </li>
                        </ul>

                    </div>
                    <!-- /.box-body -->
                </div>
            </div>

            <div class="col-md-9">

                <?php Template::loadChild('public/alerts'); ?>

                <div class="nav-tabs-custom">
                    <ul class="nav nav-tabs">
                        <li class="active"><a href="#settings" data-toggle="tab" aria-expanded="true">Account Settings</a></li>
                        <li class=""><a href="#api" data-toggle="tab" aria-expanded="false">API Credentials</a></li>
                        <li class=""><a href="#data" data-toggle="tab" aria-expanded="false">Account Deletion</a></li>
                        <li class=""><a href="#2fa" data-toggle="tab" aria-expanded="false">Two Factor Authentication</a></li>
                    </ul>
                    <div class="tab-content">
                        <div class="tab-pane active" id="settings">
                            <form method="post" action="">
                                <h4 class="lead"> <i class="fa fa-user"></i> Basic Information</h4>

                                <div class="form-group  col-md-6">
                                    <label class="control-label"> First Name </label>
                                    <input class="form-control" name="first_name" value="<?= h($user->first_name) ?>" autocomplete="off">
                                </div>
                                <div class="form-group  col-md-6">
                                    <label class="control-label"> Last Name </label>
                                    <input class="form-control" name="last_name" value="<?= h($user->last_name) ?>" autocomplete="off">
                                </div>
                                <div class="form-group  col-md-12">
                                    <label class="control-label"> Affiliation </label>
                                    <input class="form-control" name="affiliation" value="<?= h($user->affiliation) ?>" autocomplete="off">
                                </div>
                                <div class="clearfix"></div>

                                <h3 class="lead"> <i class="fa fa-lock"></i> Login Details (changes are effective immediately)</h3>
                                <div class="alert alert-warning col-md-7" style="font-size: 16px;">
                                    <i class="fa fa-warning"></i> &nbsp; If you do not intend to change your password, please leave the password fields empty.
                                </div>
                                <div class="clearfix"></div>

                                <div class="form-group ">
                                    <label class="control-label" for="email"><i class="fa fa-envelope-o fa-fw"></i> New Email</label>
                                    <input class="form-control" type="email" id="email" name="new_email" value="<?= h($user->email) ?>" autocomplete="new-password">
                                </div>

                                <div class="form-group ">
                                    <label class="control-label" for="pass2"><i class="fa fa-key fa-fw"></i> Enter New Password (Choose a secure phrase)</label>
                                    <input class="form-control" type="password" id="pass2" name="new_password" autocomplete="new-password">
                                </div>
                                <div class="form-group ">
                                    <label class="control-label" for="pass3"><i class="fa fa-key fa-fw"></i> Confirm New Password</label>
                                    <input class="form-control" type="password" id="pass3" name="new_password_c" autocomplete="new-password">
                                </div>
                                <p>&nbsp;</p>

                                <div class="col-md-5 no-padding confirm-changes">
                                    <label class="control-label" for="pass"><i class="fa fa-check-circle"></i> Enter Old Password to Save Changes</label>
                                    <div class="input-group input-group">
                                        <input class="form-control" type="password" id="pass" name="password" autocomplete="new-password" placeholder="Old Password">
                                        <span class="input-group-btn">
                                            <button type="submit" class="btn btn-raised btn-primary btn-flat"><i class="fa fa-save"></i> Save Changes</button>
                                        </span>
                                    </div>
                                </div>
                                <div class="clearfix"></div>
                            </form>

                            <div class="clearfix"></div>
                        </div>

                        <div class="tab-pane" id="api">
                            <h4 class="lead"> <i class="fa fa-lock"></i> API Credentials</h4>
                            <p>
                                The <code>formr</code> R package is the easiest way to use these credentials. Install it from GitHub with the <code>remotes</code> package:
                            </p>
                            <pre><code class="r copy-on-click">install.packages("remotes")
remotes::install_github("rubenarslan/formr")</code></pre>
                            <p>
                                See the <a href="https://rubenarslan.github.io/formr/" target="_blank" rel="noopener">package documentation</a> for full usage. You can hold several credentials side by side — give each one a label and pick the scopes it needs for its specific use.
                            </p>
                            <?php if ($can_access_api): ?>
                            <div id="api-credentials-panel"
                                 data-endpoint="<?= admin_url('account/api-credentials') ?>">

                                <div id="api-credentials-list-wrap">
                                    <h4 class="lead" style="margin-top: 20px;">Your credentials</h4>
                                    <?php if (empty($api_credentials_list)): ?>
                                        <p class="text-muted"><em>You have no API credentials yet. Create one below.</em></p>
                                    <?php else: ?>
                                        <table class="table table-bordered api-credentials-list">
                                            <thead>
                                                <tr>
                                                    <th>Label</th>
                                                    <th>Client ID</th>
                                                    <th>Scopes</th>
                                                    <th>Runs</th>
                                                    <th></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($api_credentials_list as $cred): ?>
                                                <tr data-client-id="<?= h($cred['client_id']) ?>" data-label="<?= h($cred['label']) ?>" data-run-ids="<?= h(implode(',', $cred['run_ids'])) ?>">
                                                    <td><strong><?= h($cred['label']) ?></strong></td>
                                                    <td><code><?= h($cred['client_id']) ?></code></td>
                                                    <td class="api-cred-scopes">
                                                        <?php if (empty($cred['scopes'])): ?>
                                                            <em class="text-muted">none — token cannot access API</em>
                                                        <?php else: ?>
                                                            <?php foreach ($cred['scopes'] as $sc): ?>
                                                                <code style="margin-right: 4px;"><?= h($sc) ?></code>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="api-cred-runs"><?= empty($cred['run_ids']) ? '<em class="text-muted">all</em>' : count($cred['run_ids']) . ' selected' ?></td>
                                                    <td>
                                                        <button type="button" class="btn btn-warning btn-xs api-rotate-btn"><i class="fa fa-refresh"></i> Rotate</button>
                                                        <button type="button" class="btn btn-danger btn-xs api-delete-btn"><i class="fa fa-trash"></i> Delete</button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                </div>

                                <div id="api-secret-once" class="hidden" style="margin-top: 20px;">
                                    <div class="alert alert-success" id="api-secret-success" style="margin-bottom: 10px;">
                                        <strong>Credential created successfully.</strong>
                                    </div>
                                    <div class="alert alert-warning">
                                        <strong>One-time display.</strong> Copy the client secret now — we only store a hash, so it cannot be recovered later.
                                    </div>
                                    <table class="table table-bordered">
                                        <tr>
                                            <td>Client ID</td>
                                            <td><code class="copy-on-click api-out-client-id"></code></td>
                                        </tr>
                                        <tr>
                                            <td>Client Secret</td>
                                            <td><code class="copy-on-click api-out-client-secret"></code></td>
                                        </tr>
                                        <tr>
                                            <td>R command</td>
                                            <td><pre><code class="r copy-on-click api-out-r-cmd"></code></pre></td>
                                        </tr>
                                    </table>
                                </div>

                                <h4 id="api-form-heading" class="lead" style="margin-top: 30px;">Create a new credential</h4>
                                <div class="api-form" data-form-mode="create">
                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="form-group">
                                                <label class="control-label" for="api-label-input">Label</label>
                                                <input id="api-label-input" type="text" maxlength="64" class="form-control"
                                                       placeholder="e.g. dashboard, cron-2026">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label class="control-label">&nbsp;</label>
                                                <button type="button" class="btn btn-primary btn-raised btn-block" id="api-create-btn" disabled>
                                                    <i class="fa fa-key"></i> <span class="api-submit-label">Create credential</span>
                                                </button>
                                                <button type="button" class="btn btn-default btn-block" id="api-cancel-rotate-btn" style="margin-top: 5px; display: none;">
                                                    Cancel
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <p class="help-block" style="margin-bottom: 25px;">Used only on this page to tell credentials apart. Must be unique within your account; internal is reserved. Enter a label to enable the create button.</p>

                                    <div class="row">
                                        <div class="col-md-7">
                                            <fieldset>
                                                <legend>Scopes</legend>
                                                <p class="help-block">
                                                    Pick exactly the capabilities this credential should grant. A token with no scopes cannot do anything.
                                                </p>

                                                <div class="checkbox">
                                                    <label>
                                                        <input type="checkbox" id="api-scope-select-all">
                                                        <strong>Select all scopes</strong>
                                                    </label>
                                                </div>
                                                <hr style="margin-top: 5px; margin-bottom: 10px;">

                                                <div class="row">
                                                    <?php $scope_chunks = array_chunk($available_scopes, 6, true); ?>
                                                    <?php foreach ($scope_chunks as $chunk): ?>
                                                    <div class="col-md-6">
                                                        <?php foreach ($chunk as $scope_key => $scope_label): ?>
                                                        <div class="checkbox">
                                                            <label>
                                                                <input type="checkbox" name="api_scope[]" value="<?= h($scope_key) ?>">
                                                                <code><?= h($scope_key) ?></code> — <?= h($scope_label) ?>
                                                            </label>
                                                        </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </fieldset>
                                        </div>

                                        <div class="col-md-5">
                                            <fieldset>
                                                <legend>Restrict to runs</legend>
                                                <p class="help-block">
                                                    Leave empty to allow this credential to act on all of your runs. Selecting one or more runs limits the credential to those runs and to surveys that are part of them.
                                                </p>
                                                <?php if (empty($user_runs)): ?>
                                                    <p class="text-muted"><em>You have no runs yet.</em></p>
                                                <?php else: ?>
                                                    <select name="api_run_ids[]" multiple class="form-control" size="<?= min(8, max(3, count($user_runs))) ?>" style="width: 100%;">
                                                        <?php foreach ($user_runs as $run_row): ?>
                                                            <option value="<?= (int) $run_row['id'] ?>"><?= h($run_row['name']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <p class="help-block" style="margin-top: 4px;"><a href="#" class="api-clear-runs">Clear selection</a></p>
                                                <?php endif; ?>
                                            </fieldset>
                                        </div>
                                    </div>
                                </div>

                                <noscript>
                                    <div class="alert alert-info" style="margin-top: 15px;">
                                        JavaScript is required to generate or rotate API credentials.
                                    </div>
                                </noscript>
                            </div>
                            <?php else: ?>
                            <?php
                            // Same pattern as register.php — surface the
                            // instance's support email so users have a real
                            // address to write to instead of "an administrator".
                            $support_email = Site::getSettings('content:docu:support_email', 'no@email.provided');
                            $support_email_link = '<a href="mailto:' . h($support_email) . '?subject=' . rawurlencode('Request API access') . '">' . h($support_email) . '</a>';
                            ?>
                            <div class="alert alert-info">
                                <i class="fa fa-info-circle"></i>
                                You do not have API access. To request API credentials, write to this instance's administrator at <?= $support_email_link ?>.
                            </div>
                            <?php endif; ?>
                            <p> &nbsp; </p>
                        </div>
                        <script>
                        (function () {
                            var $panel = jQuery('#api-credentials-panel');
                            if (!$panel.length) { return; }
                            var endpoint = $panel.data('endpoint');
                            var apiHost = <?= json_encode(api_base_url()) ?>;
                            var $form = $panel.find('.api-form');
                            var $submitBtn = $panel.find('#api-create-btn');
                            var $submitLabel = $panel.find('.api-submit-label');
                            var $cancelBtn = $panel.find('#api-cancel-rotate-btn');
                            var $labelInput = $panel.find('#api-label-input');
                            var $formHeading = $panel.find('#api-form-heading');
                            // rotate state — when non-null the form submits a rotate
                            // for this client_id (label is fixed and disabled).
                            var rotateClientId = null;

                            function rCommand(clientId, clientSecret) {
                                return 'library(formr)\n' +
                                    'formr_store_keys(host = "' + apiHost +
                                    '", client_id = "' + clientId +
                                    '", client_secret = "' + clientSecret + '")\n' +
                                    'formr_api_authenticate(host = "' + apiHost + '")';
                            }

                            function collectSelections() {
                                var scopes = $form.find('input[name="api_scope[]"]:checked').map(function () {
                                    return this.value;
                                }).get();
                                var runIds = $form.find('select[name="api_run_ids[]"] option:selected').map(function () {
                                    return this.value;
                                }).get();
                                return { scope: scopes, run_ids: runIds };
                            }

                            function enterCreateMode() {
                                rotateClientId = null;
                                $form.attr('data-form-mode', 'create');
                                $labelInput.prop('disabled', false).val('');
                                $submitBtn.prop('disabled', true);
                                $submitLabel.text('Create credential');
                                $submitBtn.removeClass('btn-warning').addClass('btn-primary');
                                $cancelBtn.hide();
                                $formHeading.text('Create a new credential');
                                $form.find('input[name="api_scope[]"]').prop('checked', false);
                                $panel.find('#api-scope-select-all').prop('checked', false);
                                $form.find('select[name="api_run_ids[]"] option:selected').prop('selected', false);
                                $panel.find('#api-secret-once').addClass('hidden');
                            }

                            function enterRotateMode(clientId, label, scopes, runIds) {
                                rotateClientId = clientId;
                                $form.attr('data-form-mode', 'rotate');
                                $labelInput.prop('disabled', true).val(label);
                                $submitBtn.prop('disabled', false);
                                $submitLabel.text('Rotate secret for "' + label + '"');
                                $submitBtn.removeClass('btn-primary').addClass('btn-warning');
                                $cancelBtn.show();
                                $formHeading.text('Rotate credential');
                                $form.find('input[name="api_scope[]"]').each(function () {
                                    this.checked = scopes.indexOf(this.value) !== -1;
                                });
                                var totalScopes = $form.find('input[name="api_scope[]"]').length;
                                var checkedScopes = $form.find('input[name="api_scope[]"]:checked').length;
                                $panel.find('#api-scope-select-all').prop('checked', totalScopes === checkedScopes);
                                $form.find('select[name="api_run_ids[]"] option').each(function () {
                                    this.selected = runIds.indexOf(parseInt(this.value, 10)) !== -1;
                                });
                                $panel.find('#api-secret-once').addClass('hidden');
                                jQuery('html, body').animate({
                                    scrollTop: $form.offset().top - 80
                                }, 200);
                            }

                            function submitForm() {
                                var sel = collectSelections();
                                if (sel.scope.length === 0
                                    && !confirm('You have not selected any scopes. A token with no scopes cannot access the API. Continue anyway?')) {
                                    return;
                                }
                                var wasCreate = (rotateClientId === null);
                                var payload;
                                if (wasCreate) {
                                    var label = jQuery.trim($labelInput.val());
                                    if (!label) {
                                        alert('Please pick a label for this credential.');
                                        return;
                                    }
                                    var existingLabels = $panel.find('.api-credentials-list tbody tr').map(function () {
                                        return jQuery(this).data('label');
                                    }).get();
                                    if (existingLabels.some(function (l) { return l.toLowerCase() === label.toLowerCase(); })) {
                                        alert('You already have a credential with the label "' + label + '". Each label must be unique within your account.');
                                        $submitBtn.prop('disabled', false);
                                        return;
                                    }
                                    payload = jQuery.extend({ api_action: 'create', label: label }, sel);
                                } else {
                                    if (!confirm('Rotating will invalidate the current secret. Continue?')) { return; }
                                    payload = jQuery.extend({ api_action: 'rotate', client_id: rotateClientId }, sel);
                                }
                                $submitBtn.prop('disabled', true);
                                jQuery.ajax({
                                    type: 'POST',
                                    url: endpoint,
                                    traditional: false,
                                    data: payload,
                                    dataType: 'json'
                                }).done(function (response) {
                                    if (!response || !response.success) {
                                        alert((response && response.message) || 'Could not issue API credentials.');
                                        $submitBtn.prop('disabled', false);
                                        return;
                                    }
                                    $panel.find('.api-out-client-id').text(response.data.client_id);
                                    $panel.find('.api-out-client-secret').text(response.data.client_secret);
                                    $panel.find('.api-out-r-cmd').text(rCommand(response.data.client_id, response.data.client_secret));
                                    $panel.find('#api-secret-success').text(
                                        wasCreate ? 'Credential created successfully.' : 'Secret rotated successfully. The old secret is now invalid.'
                                    );
                                    $panel.find('#api-secret-once').removeClass('hidden');

                                    // Escape for HTML element-and-attribute contexts. .text().html()
                                    // misses ", which would break double-quoted attributes \u2014 so
                                    // do the four substitutions explicitly.
                                    var escAttr = function (s) {
                                        return String(s)
                                            .replace(/&/g, '&amp;')
                                            .replace(/</g, '&lt;')
                                            .replace(/>/g, '&gt;')
                                            .replace(/"/g, '&quot;');
                                    };
                                    var scopeHtml = sel.scope.length === 0
                                        ? '<em class="text-muted">none \u2014 token cannot access API</em>'
                                        : sel.scope.map(function (s) { return '<code style="margin-right: 4px;">' + escAttr(s) + '</code>'; }).join(' ');
                                    var runHtml = sel.run_ids.length === 0
                                        ? '<em class="text-muted">all</em>'
                                        : sel.run_ids.length + ' selected';

                                    if (wasCreate) {
                                        // Dynamically add the new row to the credentials table
                                        var $table = $panel.find('.api-credentials-list');
                                        var rowHtml = '<tr data-client-id="' + escAttr(response.data.client_id) + '" data-label="' + escAttr(response.data.label) + '" data-run-ids="' + escAttr(sel.run_ids.join(',')) + '">'
                                            + '<td><strong>' + escAttr(response.data.label) + '</strong></td>'
                                            + '<td><code>' + escAttr(response.data.client_id) + '</code></td>'
                                            + '<td class="api-cred-scopes">' + scopeHtml + '</td>'
                                            + '<td class="api-cred-runs">' + runHtml + '</td>'
                                            + '<td>'
                                            + '<button type="button" class="btn btn-warning btn-xs api-rotate-btn"><i class="fa fa-refresh"></i> Rotate</button> '
                                            + '<button type="button" class="btn btn-danger btn-xs api-delete-btn"><i class="fa fa-trash"></i> Delete</button>'
                                            + '</td>'
                                            + '</tr>';
                                        if ($table.length === 0) {
                                            var $wrap = $panel.find('#api-credentials-list-wrap');
                                            $wrap.find('p.text-muted').remove();
                                            $wrap.append(
                                                '<table class="table table-bordered api-credentials-list">'
                                                + '<thead><tr><th>Label</th><th>Client ID</th><th>Scopes</th><th>Runs</th><th></th></tr></thead>'
                                                + '<tbody>' + rowHtml + '</tbody>'
                                                + '</table>'
                                            );
                                        } else {
                                            $table.find('tbody').prepend(rowHtml);
                                        }
                                    } else {
                                        // Update the existing row with the new scopes/runs
                                        var $row = $panel.find('.api-credentials-list tr[data-client-id="' + escAttr(rotateClientId) + '"]');
                                        if ($row.length) {
                                            $row.attr('data-run-ids', sel.run_ids.join(','));
                                            $row.find('.api-cred-scopes').html(scopeHtml);
                                            $row.find('.api-cred-runs').html(runHtml);
                                        }
                                    }
                                    // Reset form back to create mode, keeping the secret visible
                                    enterCreateMode();
                                    $panel.find('#api-secret-once').removeClass('hidden');
                                }).fail(function () {
                                    alert('Request failed.');
                                    $submitBtn.prop('disabled', false);
                                });
                            }

                            $panel.on('click', '.api-rotate-btn', function () {
                                var $row = jQuery(this).closest('tr');
                                var clientId = $row.data('client-id');
                                var label = $row.data('label');
                                // Pre-fetch the current scopes/runs from the row's badge column
                                var scopes = $row.find('.api-cred-scopes code').map(function () { return jQuery(this).text(); }).get();
                                // The row only shows a count of runs, not the actual ids — leave runs unselected
                                // and the user can re-pick. (Keeping the previous allowlist would require an extra
                                // round-trip.)
                                enterRotateMode(clientId, label, scopes, ($row.attr('data-run-ids') || '').split(',').filter(Boolean).map(Number));
                            });
                            $panel.on('click', '.api-delete-btn', function () {
                                var $row = jQuery(this).closest('tr');
                                var clientId = $row.data('client-id');
                                var label = $row.data('label');
                                if (!confirm('Delete the "' + label + '" credential? This cannot be undone — any service still using it will get 401 on the next call.')) { return; }
                                jQuery.ajax({
                                    type: 'POST',
                                    url: endpoint,
                                    data: { api_action: 'delete', client_id: clientId },
                                    dataType: 'json'
                                }).done(function (response) {
                                    if (response && response.success) {
                                        var $tbody = $row.closest('tbody');
                                        $row.remove();
                                        if (0 === $tbody.children('tr').length) {
                                            $tbody.closest('table').replaceWith(
                                                '<p class="text-muted"><em>You have no API credentials yet. Create one below.</em></p>'
                                            );
                                        }
                                        if (clientId === rotateClientId) enterCreateMode();
                                    } else {
                                        alert((response && response.message) || 'Could not delete credential.');
                                    }
                                }).fail(function () { alert('Request failed.'); });
                            });
                            $panel.on('click', '#api-create-btn', submitForm);
                            $panel.on('click', '#api-cancel-rotate-btn', enterCreateMode);
                            $panel.on('click', '.api-clear-runs', function (e) {
                                e.preventDefault();
                                $form.find('select[name="api_run_ids[]"] option').prop('selected', false);
                            });

                            // "Select all" toggles every scope checkbox
                            $panel.on('change', '#api-scope-select-all', function () {
                                var checked = this.checked;
                                $form.find('input[name="api_scope[]"]').prop('checked', checked);
                            });

                            // Unchecking a scope unchecks "Select all"; re-checking all re-checks it
                            $panel.on('change', 'input[name="api_scope[]"]', function () {
                                var all = $form.find('input[name="api_scope[]"]');
                                var checked = all.filter(':checked');
                                $panel.find('#api-scope-select-all').prop('checked', all.length === checked.length);
                            });

                            // Enable create button only when a label is entered
                            $labelInput.on('input', function () {
                                $submitBtn.prop('disabled', jQuery.trim(this.value) === '');
                            });
                        })();
                        </script>
                        <!-- /.tab-pane -->
                        <div class="tab-pane" id="data">
                            <form method="post" action="">
                                <h4 class="lead"> <i class="fa fa-trash"></i> Account Deletion</h4>
                                <div class="alert alert-danger">
                                    <strong>Warning!</strong> This action cannot be undone. All your data, including surveys, runs, and email accounts will be permanently deleted.
                                </div>

                                <div class="row">
                                    <div class="form-group col-md-8">
                                        <label class="control-label" for="delete_confirm">Type "I understand my data will be gone"</label>
                                        <input class="form-control" type="text" id="delete_confirm" name="delete_confirm" required
                                            placeholder="I understand my data will be gone" autocomplete="off">
                                    </div>

                                    <div class="form-group col-md-8">
                                        <label class="control-label" for="delete_email">Type your current email address</label>
                                        <input class="form-control" type="text" id="delete_email" name="delete_email" required
                                            placeholder="<?= h($user->email) ?>" autocomplete="off">
                                    </div>

                                    <div class="form-group col-md-8">
                                        <label class="control-label" for="delete_password">Current Password</label>
                                        <input class="form-control" type="password" id="delete_password" name="delete_password" required autocomplete="current-password">
                                    </div>

                                    <?php if ($user->is2FAenabled()): ?>
                                        <div class="form-group col-md-8">
                                            <label class="control-label" for="delete_2fa">Two-Factor Authentication Code</label>
                                            <input class="form-control" type="text" id="delete_2fa" name="delete_2fa" required placeholder="Enter your 2FA code" autocomplete="one-time-code">
                                        </div>
                                    <?php endif; ?>

                                    <div class="form-group col-md-5">
                                        <button type="submit" name="delete_account" value="true" class="btn btn-danger btn-raised">
                                            <i class="fa fa-trash"></i> Permanently Delete Account
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <!-- /.tab-pane -->
                        <div class="tab-pane" id="2fa">

                            <h4 class="lead"> <i class="fa fa-lock"></i> Login security</h4>
                            <?php if (!Config::get('2fa.enabled', true)): ?>
                                <div class="alert alert-info">
                                    <i class="fa fa-info-circle"></i> Two-factor authentication is not enabled on this instance.
                                </div>
                            <?php elseif ($user->is2FAenabled()): ?>
                                <div class="alert alert-success">
                                    <i class="fa fa-check-circle"></i> Two-factor authentication is enabled for your account.
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <a href="<?= admin_url('account/manage-two-factor') ?>" class="btn btn-raised btn-warning">
                                            <i class="fa fa-cog"></i> Manage 2FA Settings
                                        </a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="row">
                                    <div class="col-md-6" style="margin-bottom: 15px;">
                                        <div class="alert alert-warning">
                                            <i class="fa fa-warning"></i> Two-factor authentication is not enabled for your account. Enable it to add an extra layer of security.
                                        </div>
                                        <p>
                                            Two-factor authentication adds an extra layer of security to your account.
                                            Once enabled, you'll need both your password and a code from your authenticator app to log in.
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <a href="<?= admin_url('account/setup-two-factor') ?>" class="btn btn-raised btn-primary">
                                            <i class="fa fa-lock"></i> Setup 2FA
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- /.tab-content -->
                </div>

            </div>
        </div>

    </section>


</div>

<?php Template::loadChild('admin/footer'); ?>