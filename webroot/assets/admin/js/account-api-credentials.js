// API-credentials panel behaviour for the account page. External 'self'
// script (no CSP nonce needed). Server values come from data- attributes
// on #api-credentials-panel; the IIFE no-ops when that element is absent.
(function () {
    // Deep-link support: /admin/account#api (linked from the API docs) should
    // open the matching account tab, not merely scroll to it. Bootstrap 3 tabs
    // don't activate from the URL hash on their own. Runs regardless of the API
    // panel below — users without API access still have the (info-only) tab.
    jQuery(function () {
        var hash = window.location.hash;
        if (hash && /^#[\w-]+$/.test(hash)) {
            jQuery('.nav-tabs a[href="' + hash + '"]').first().tab('show');
        }
    });

    var $panel = jQuery('#api-credentials-panel');
    if (!$panel.length) { return; }
    var endpoint = $panel.data('endpoint');
    var apiHost = $panel.attr("data-api-host");
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
