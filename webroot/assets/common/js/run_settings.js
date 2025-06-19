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
                    $(data).insertAfter($("#app_heading"));
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
                        // Update the manifest textarea if it exists
                        var $manifestArea = $('#manifest_json');
                        if ($manifestArea.length) {
                            const manifest_json = JSON.stringify(response, null, 2);
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
    });

})();
