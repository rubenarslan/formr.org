import $ from 'jquery';
import { ajaxErrorHandling, bootstrap_alert } from './main.js';

(function () {
    "use strict";
    function make_editor(i, elm) {
        var textarea = $(elm);
        var mode = textarea.data('editor');

        var editDiv = $('<div>', {
            position: 'absolute',
            width: "100%",
            height: textarea.height(),
            'class': textarea.attr('class')
        }).insertBefore(textarea);

        textarea.css('display', 'none');

        var editor = ace.edit(editDiv[0]);
        editor.setOptions({
            minLines: textarea.attr('rows') ? textarea.attr('rows') : 3,
            maxLines: 200
        });
        editor.setTheme("ace/theme/textmate");
        var session = editor.getSession();
        session.setValue(textarea.val());
        textarea.on('change', function () {
            session.setValue(textarea.val());
        });

        session.setUseWrapMode(true);
        session.setMode("ace/mode/" + mode);

        var form = $(elm).parents('form');
        editor.on('change', function () {
            form.trigger("change");
        });
        // Ensure textarea is updated before ANY form submission (AJAX or otherwise)
        form.on('submit ajax_submission', function () {
            textarea.val(session.getValue());
        });
    }
    function save_settings(i, elm) {
        $(elm).prop("disabled", true);
        var form = $(elm).parents('form');
        form.change(function () {
            $(elm).prop("disabled", false);
        }).submit(function () {
            // Prevent default submit for forms containing .save_settings buttons ONLY if triggered by non-button submit
            // The button click handler below will handle submission via AJAX
            return false; 
        });
        $(elm).click(function (e) {
            form.trigger("ajax_submission"); // Sync ACE editor
            e.preventDefault();
            $.ajax({
                url: form.attr('action'),
                dataType: 'html',
                data: form.serialize(),
                method: 'POST'
            }).done(function (data) {
                if (data !== '')
                    $(data).prependTo(form.closest('.tab-pane'));
                $(elm).prop("disabled", true);
            }).fail(function (e, x, settings, exception) {
                ajaxErrorHandling(e, x, settings, exception);
                $(elm).prop("disabled", false); // Re-enable button on failure
            });

            return false;
        });
    }

    // PWA Icon Upload and Clear Logic (Async/Await)
    async function handlePwaIconUpload(event) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);
        const submitButton = form.querySelector('button[type="submit"]');

        if (submitButton) {
            submitButton.disabled = true;
        }

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            
            if (data.success) {
                bootstrap_alert(data.messages.join('<br>'), 'Success', '.alerts-container', 'alert-success');
                // Reload to see changes (updated path, potentially clear button visibility)
                setTimeout(() => location.reload(), 1000);
            } else {
                bootstrap_alert(data.messages.join('<br>'), 'Error', '.alerts-container', 'alert-danger');
                if (submitButton) {
                    submitButton.disabled = false;
                }
            }
        } catch (error) {
            console.error('PWA Icon Upload Error:', error);
            bootstrap_alert('An error occurred during upload: ' + error.message, 'Error', '.alerts-container', 'alert-danger');
            if (submitButton) {
                submitButton.disabled = false;
            }
        }
    }

    async function handleClearPwaIcons() {
        if (!confirm('Are you sure you want to clear all PWA icons? This will delete the files and remove the path setting.')) {
            return;
        }
        
        const clearButton = document.getElementById('clear_pwa_icons_button');
        if (clearButton) {
            clearButton.disabled = true;
        }

        // We need the clear URL. Let's assume it's stored in a data attribute on the button.
        const clearUrl = clearButton?.dataset.actionUrl; 
        if(!clearUrl) {
             bootstrap_alert('Could not find clear URL. The data-action-url attribute might be missing on the clear button.', 'Error', '.alerts-container', 'alert-danger');
             console.error('Clear PWA Icons button missing data-action-url attribute');
             if (clearButton) {
                clearButton.disabled = false;
            }
             return;
        }

        try {
            const response = await fetch(clearUrl, {
                method: 'POST' // Assuming POST is appropriate
            });
            const data = await response.json();

            if (data.success) {
                bootstrap_alert(data.messages.join('<br>'), 'Success', '.alerts-container', 'alert-success');
                setTimeout(() => location.reload(), 1000);
            } else {
                bootstrap_alert(data.messages.join('<br>'), 'Error', '.alerts-container', 'alert-danger');
                 if (clearButton) {
                    clearButton.disabled = false;
                }
            }
        } catch (error) {
            console.error('Clear PWA Icons Error:', error);
            bootstrap_alert('An error occurred while clearing icons: ' + error.message, 'Error', '.alerts-container', 'alert-danger');
            if (clearButton) {
                clearButton.disabled = false;
            }
        }
    }

    // Attach listeners after DOM is ready
    $(function () {
        $('textarea.big_ace_editor').each(make_editor);
        $(".save_settings").each(save_settings);
        // Handle manifest generation
        $('.generate-manifest').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('clicked');
            e.stopImmediatePropagation(); // Stop any other click handlers on this element
            
            var $btn = $(this);
            var originalHtml = $btn.html();
            
            // Show loading state
            $btn.html('<i class="fa fa-spinner fa-spin"></i> Generating...').prop('disabled', true);
            
            $.ajax({
                url: $btn.data('href'),
                type: 'POST',
                dataType: 'json',
                success: function(response) {
                    if (response.error) {
                        alert(response.error);
                    } else {
                        if (response.cookie_expiry_adjusted) {
                            bootstrap_alert(
                                'PWA manifest generated.<br><br>Cookie expiry was automatically increased to 1 year so the study can resume after closing the browser/app. You might want to include a request_cookie item in your PWA onboarding survey, to ensure that participants have given permissions to track them across sessions. Otherwise, the PWA will break after a few days.',
                                'Notice',
                                '.alerts-container',
                                'alert-warning'
                            );
                        } else {    
                            bootstrap_alert(
                                'PWA manifest generated.<br><br>Cookie expiry was already >= 1 year, so the study can resume after closing the browser/app. You might want to include a request_cookie item in your PWA onboarding survey, to ensure that participants have given permissions to track them across sessions. Otherwise, the PWA will break after a few days.',
                                'Notice',
                                '.alerts-container',
                                'alert-warning'
                            );
                        }

                        const manifest = response.manifest || response;
                        // Update the manifest textarea if it exists
                        var $manifestArea = $('#manifest_json');
                        if ($manifestArea.length) {
                            const manifest_json = JSON.stringify(manifest, null, 2);
                            $manifestArea.val(manifest_json);
                            $manifestArea.trigger('change');
                        }
                    }
                },
                error: function(xhr) {
                    alert('Failed to generate manifest: ' + (xhr.responseText || 'Unknown error'));
                },
                complete: function() {
                    // Restore button state
                    $btn.html(originalHtml).prop('disabled', false);
                }
            });
        });

        // Initialize PWA Icon Upload Form Handler
        const pwaIconsForm = document.getElementById('pwa_icons_form');
        if (pwaIconsForm) {
            pwaIconsForm.addEventListener('submit', handlePwaIconUpload);
        }

        // Initialize PWA Icon Clear Button Handler
        const clearPwaIconsButton = document.getElementById('clear_pwa_icons_button');
        if (clearPwaIconsButton) {
            clearPwaIconsButton.addEventListener('click', handleClearPwaIcons);
        }

        // --- Secrets management (extracted from settings.php inline JS) ---
        {
            const secretsTable = document.getElementById('secrets-table');
            if (secretsTable) {
                const secretsTbody = document.getElementById('secrets-tbody');
                const secretsSaveUrl = secretsTable.dataset.saveUrl;
                const secretsSaveIndicator = document.getElementById('secrets-save-indicator');
                const secretsAlertsContainer = document.getElementById('secrets-alerts');

                jQuery('[data-toggle="tooltip"]').tooltip();

                function collectSecrets() {
                    var secrets = {};
                    var rows = secretsTbody.querySelectorAll('tr');
                    rows.forEach(function(row) {
                        var nameInput = row.querySelector('.secret-name-hidden');
                        var valueInput = row.querySelector('.secret-value');
                        if (nameInput && valueInput) {
                            var name = nameInput.value.trim();
                            if (name) {
                                secrets[name] = valueInput.value;
                            }
                        }
                    });
                    return secrets;
                }

                function hasSecretName(name) {
                    var found = false;
                    var rows = secretsTbody.querySelectorAll('tr');
                    rows.forEach(function(row) {
                        var input = row.querySelector('.secret-name-hidden');
                        if (input && input.value.trim() === name) {
                            found = true;
                        }
                    });
                    return found;
                }

                function saveSecrets() {
                    var secrets = collectSecrets();
                    secretsSaveIndicator.style.visibility = 'visible';

                    var formData = new FormData();
                    formData.append('secrets_json', JSON.stringify(secrets));

                    fetch(secretsSaveUrl, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function(r) { return r.text(); })
                        .then(function(html) {
                            secretsSaveIndicator.innerHTML = '<i class="fa fa-check"></i> Saved';
                            if (html.indexOf('alert-danger') !== -1) {
                                secretsAlertsContainer.innerHTML = html;
                            }
                            setTimeout(function() {
                                secretsSaveIndicator.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';
                                secretsSaveIndicator.style.visibility = 'hidden';
                            }, 1200);
                        })
                        .catch(function() {
                            secretsSaveIndicator.style.visibility = 'hidden';
                            secretsAlertsContainer.innerHTML = '<div class="alert alert-danger">Failed to save secrets.</div>';
                        });
                }

                secretsTbody.addEventListener('click', function(e) {
                    var btn = e.target.closest('.secret-toggle');
                    if (btn) {
                        var wrap = btn.closest('.secret-value-wrap');
                        if (!wrap) return;
                        var input = wrap.querySelector('.secret-value');
                        if (!input) return;
                        input.classList.toggle('secret-masked');
                        btn.querySelector('i').className = input.classList.contains('secret-masked') ? 'fa fa-eye' : 'fa fa-eye-slash';
                        return;
                    }

                    var del = e.target.closest('.delete-secret');
                    if (del) {
                        jQuery(del).tooltip('destroy');
                        del.closest('tr').remove();
                        saveSecrets();
                    }
                });

                secretsTbody.addEventListener('blur', function(e) {
                    var input = e.target.closest('.secret-value');
                    if (input) {
                        saveSecrets();
                    }
                }, true);

                document.getElementById('new-secret-name').addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('add-secret-btn').click(); }
                });
                document.getElementById('new-secret-value').addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('add-secret-btn').click(); }
                });

                document.getElementById('add-secret-btn').addEventListener('click', function() {
                    var nameInput = document.getElementById('new-secret-name');
                    var valueInput = document.getElementById('new-secret-value');
                    var name = nameInput.value.trim();
                    var value = valueInput.value.trim();

                    if (!name) { alert('Please enter a secret name.'); return; }
                    if (!value) { alert('Please enter a secret value.'); return; }
                    if (hasSecretName(name)) {
                        alert('A secret with this name already exists.');
                        return;
                    }

                    var safeName = name.replace(/[<>&"']/g, '');
                    var safeValue = value.replace(/[<>&"']/g, '');
                    var tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td><code>secret_' + safeName + '</code></td>' +
                        '<td><input type="hidden" class="secret-name-hidden" value="' + safeName + '"><div class="secret-value-wrap"><input type="text" class="form-control input-sm secret-value secret-masked" value="' + safeValue + '"><button type="button" class="secret-toggle" data-toggle="tooltip" title="Toggle visibility"><i class="fa fa-eye"></i></button></div></td>' +
                        '<td><button type="button" class="btn btn-danger btn-xs delete-secret" data-toggle="tooltip" title="Delete secret"><i class="fa fa-trash"></i></button></td>';
                    secretsTbody.appendChild(tr);
                    jQuery(tr).find('[data-toggle="tooltip"]').tooltip();

                    nameInput.value = '';
                    valueInput.value = '';
                    nameInput.focus();
                    saveSecrets();
                });
            }
        }

        // --- Save & test R Syntax (extracted from settings.php inline JS) ---
        {
            const rForm = document.querySelector('#r-functions form');
            if (rForm) {
                const rResultEl = document.getElementById('r-code-parse-result');
                if (rResultEl) {
                    const rValidateUrl = rForm.dataset.validateUrl;
                    let rBusy = false;

                    function escapeHtml(text) {
                        var d = document.createElement('div');
                        d.appendChild(document.createTextNode(text));
                        return d.innerHTML;
                    }

                    rForm.querySelector('.btn-save-test-r-code').addEventListener('click', async function() {
                        if (rBusy) return;
                        rBusy = true;
                        jQuery(rForm).trigger('ajax_submission');
                        var textarea = rForm.querySelector('textarea[name="custom_r"]');
                        if (!textarea) { rBusy = false; return; }
                        var code = textarea.value;

                        rResultEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…';

                        try {
                            var saveRes = await fetch(rForm.action, {
                                method: 'POST',
                                body: new FormData(rForm),
                                headers: { 'X-Requested-With': 'XMLHttpRequest' }
                            });
                            if (!saveRes.ok) {
                                rBusy = false;
                                rResultEl.innerHTML = '<span class="text-warning"><i class="fa fa-warning"></i> Save failed</span>';
                                return;
                            }

                            var saveHtml = await saveRes.text();
                            if (saveHtml) {
                                var pane = rForm.closest('.tab-pane');
                                if (pane) {
                                    var tmp = document.createElement('div');
                                    tmp.innerHTML = saveHtml;
                                    while (tmp.firstChild) {
                                        pane.insertBefore(tmp.firstChild, pane.firstChild);
                                    }
                                }
                            }

                            if (code.trim() === '') {
                                rBusy = false;
                                rResultEl.innerHTML = '';
                                return;
                            }

                            rResultEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Checking syntax…';
                            var fd = new FormData();
                            fd.append('r_code', code);
                            var checkRes = await fetch(rValidateUrl, {
                                method: 'POST',
                                body: fd,
                                headers: { 'X-Requested-With': 'XMLHttpRequest' }
                            });
                            var data = await checkRes.json();
                            rBusy = false;

                            if (data.valid === true) {
                                rResultEl.innerHTML = '<span class="text-success"><i class="fa fa-check"></i> R syntax is valid</span>';
                            } else if (data.valid === false) {
                                var rLastCode = code;
                                var rLastError = data.message;
                                rResultEl.innerHTML = '<pre class="text-danger" style="white-space: pre-wrap; margin: 8px 0">'
                                    + escapeHtml(data.message)
                                    + '</pre>'
                                    + '<div style="margin: 6px 0"><p class="pull-right"><button type="button" class="btn btn-sm btn-default" title="Copy code + error for LLM"><i class="fa fa-clipboard"></i> Copy for LLM</button></p></div>';
                                var copyBtn = rResultEl.querySelector('button');
                                if (copyBtn) {
                                    copyBtn.addEventListener('click', function() {
                                        var text = 'TASK: Debug this R code syntax error.\n\nCODE:\n' + rLastCode + '\n\nERROR:\n' + rLastError;
                                        navigator.clipboard.writeText(text);
                                    });
                                }
                            } else {
                                rResultEl.innerHTML = '<span class="text-warning"><i class="fa fa-warning"></i> ' + (data.message || 'Could not validate') + '</span>';
                            }
                        } catch (e) {
                            rBusy = false;
                            rResultEl.innerHTML = '<span class="text-warning"><i class="fa fa-warning"></i> Save or syntax check failed</span>';
                        }
                    });
                }
            }
        }
    });

})();
