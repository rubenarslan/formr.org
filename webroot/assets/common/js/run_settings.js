(function ($) {
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
        form.on('ajax_submission', function () {
            textarea.val(session.getValue());
        });
    }
    function save_settings(i, elm) {
        $(elm).prop("disabled", true);
        var form = $(elm).parents('form');
        form.change(function () {
            $(elm).prop("disabled", false);
        }).submit(function () {
            return false;
        });
        $(elm).click(function (e) {
            form.trigger("ajax_submission");
            e.preventDefault();
            $.ajax({
                url: form.attr('action'),
                dataType: 'html',
                data: form.serialize(),
                method: 'POST'
            }).done(function (data) {
                if (data !== '')
                    $(data).insertBefore(form);
                $(elm).prop("disabled", true);
            }).fail(function (e, x, settings, exception) {
                ajaxErrorHandling(e, x, settings, exception);
            });

            return false;
        });
    }
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
    });

}(jQuery));