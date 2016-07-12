(function ($) {
    "use strict";
    function ajaxifyLink(i, elm) {
        $(elm).click(function (e)
        {
            e.preventDefault();
            var $this = $(this);
            var old_href = $this.attr('href');
            if (old_href === '')
                return false;
            $this.attr('href', '');

            $.ajax(
                    {
                        type: "GET",
                        url: old_href,
                        dataType: 'html'
                    })
                    .done($.proxy(function (data)
                    {
                        var $this = $(this);
                        $this.attr('href', old_href);
                        if (!$this.hasClass("danger"))
                            $this.css('color', 'green');
                        var $logo = $this.find('i.fa');

                        if ($logo.hasClass("fa-stethoscope")) {
                            $logo.addClass('fa-heartbeat');
                            $logo.removeClass('fa-stethoscope');
                        } else if ($logo.hasClass("fa-heartbeat")) {
                            $logo.removeClass('fa-heartbeat');
                            $logo.addClass('fa-stethoscope');
                        } else {
                            bootstrap_modal('Alert', data, 'tpl-feedback-modal');
                        }

                        if ($this.hasClass('refresh_on_success')) {
                            document.location.reload(true);
                        }
                    }, this))
                    .fail($.proxy(function (e, x, settings, exception) {
                        $(this).attr('href', old_href);
                        ajaxErrorHandling(e, x, settings, exception);
                    }, this));
            return false;
        });
    }

    function ajaxifyForm(i, elm) {
        $(elm).submit(function (e)
        {
            e.preventDefault();
            var $this = $(this);
            var $submit = $this.find('button[type=submit].btn');
            $submit.attr('disabled', true);

            $.ajax(
                    {
                        type: $this.attr('method'),
                        url: $this.attr('action'),
                        data: $this.serialize(),
                        dataType: 'html',
                    })
                    .done($.proxy(function (data)
                    {
                        $submit.attr('disabled', false);
                        $submit.css('color', 'green');
                        $('.main_body').prepend(data);

                        if ($submit.hasClass('refresh_on_success')) {
                            document.location.reload(true);
                        }
                    }, this))
                    .fail($.proxy(function (e, x, settings, exception) {
                        $submit.attr('disabled', false);
                        ajaxErrorHandling(e, x, settings, exception);
                    }, this));
            return false;
        });
    }

    function userAPIAccess(e) {
        /*jshint validthis:true */

        var userId = parseInt($(this).data('user'), 10);
        var userEmail = $(this).data('email');
        if (!userId || !userEmail) {
            return;
        }

        var data = {user_id: userId, user_email: userEmail, user_api: true, api_action: 'get'};
        postdata(saAjaxUrl, data, function (response) {
            if (response && response.success) {
                userAPIModal(response.data, data);
            }
        });


    }

    function userAPIModal(data, meta) {
        var $modal = $($.parseHTML(getHTMLTemplate('tpl-user-api', {user: ' (' + data.user + ')', client_id: data.client_id, client_secret: data.client_secret})));

        if (data.client_id) {
            $modal.find('.api-create').remove();
        } else {
            $modal.find('.api-change, .api-delete').remove();
        }

        $modal.on('shown.bs.modal', function () {
            $modal.find('.api-create').click(function () {
                if (!confirm("Are you sure?"))
                    return;
                var request = {user_id: meta.user_id, user_email: meta.user_email, user_api: true, api_action: 'create'};
                postdata(saAjaxUrl, request, function (response) {
                    if (response && response.success) {
                        $modal.modal('hide');
                        userAPIModal(response.data, meta);
                    }
                });
            });

            $modal.find('.api-change').click(function () {
                if (!confirm("Are you sure?"))
                    return;
                var request = {user_id: meta.user_id, user_email: meta.user_email, user_api: true, api_action: 'change'};
                postdata(saAjaxUrl, request, function (response) {
                    if (response && response.success) {
                        $modal.modal('hide');
                        userAPIModal(response.data, meta);
                    }
                });
            });

            $modal.find('.api-delete').click(function () {
                if (!confirm("Are you sure?"))
                    return;
                var request = {user_id: meta.user_id, user_email: meta.user_email, user_api: true, api_action: 'delete'};
                postdata(saAjaxUrl, request, function (response) {
                    if (response && response.success) {
                        $modal.modal('hide');
                        userAPIModal({user: data.user, 'client_id': '', 'client_secret': ''}, meta);
                    }

                });
            });

        }).on('hidden.bs.modal', function () {
            $modal.remove();
        }).modal('show');
    }

    function deleteUserSession(e) {
        /*jshint validthis:true */
        var $btn = $(this);
        var $modal = $($.parseHTML(getHTMLTemplate('tpl-delete-run-session', {action: $btn.data('href'), session: $btn.data('session')})));
        var $parent_tr = $btn.parents('tr');
        $btn.css("border-color", "#ee5f5b");
        $btn.css("color", "#ee5f5b");

        $modal.find('form').each(ajaxifyForm).submit(function() {
            $parent_tr.css("background-color", "#ee5f5b");
            $modal.modal('hide');
        });
        $modal.on('hidden.bs.modal', function () {
            $modal.remove();
            $btn.css("border-color", "black");
            $btn.css("color", "black");
        }).modal('show');

    }
    
    function remindUserSession(e) {
        /*jshint validthis:true */
        var $btn = $(this);
        var $modal = $($.parseHTML(getHTMLTemplate('tpl-remind-run-session', {action: $btn.data('href'), session: $btn.data('session')})));
        var $parent_tr = $btn.parents('tr');
        $btn.css("border-color", "#ee5f5b");
        $btn.css("color", "#ee5f5b");

        $modal.on('shown.bs.modal', function() {
            $modal.find('.send').click(function() {
                var href = $btn.data('href'), reminder = $(this).data('reminder');
                $(this).append(bootstrap_spinner());
                postdata(href, {reminder: reminder}, function(response) {
                    $modal.modal('hide');
                    bootstrap_modal('Send Reminder', response, 'tpl-feedback-modal').modal('show');
                }, 'html');
            });
        });

        $modal.on('hidden.bs.modal', function () {
            $modal.remove();
            $btn.css("border-color", "black");
            $btn.css("color", "black");
        }).modal('show');

    }

    function postdata(url, data, callback, type, errCallback) {
        type = type || 'json';
        errCallback = errCallback || function(){};
        $.ajax({
            type: 'POST',
            url: url,
            data: data,
            dataType: type,
            success: function (response, code, jqxhr) {
                callback(response);
            },
            error: function (jqxhr, errText) {
                $('.main_body').prepend(errText);
                errCallback(jqxhr, errText);
            },
            beforeSend: function (jqxhr) {
                //alert(this.url);
            }
        });
    }

    function toggleSessionSearch() {
        /*jshint validthis:true */
        var $me = $(this);
        if ($me.data('active') === 'single') {
            $me.siblings('.single').addClass('hidden');
            $me.siblings('.multiple').removeClass('hidden');
            $me.data('active', 'multiple');
        } else {
            $me.siblings('.single').removeClass('hidden');
            $me.siblings('.multiple').addClass('hidden');
            $me.data('active', 'single');
        }

    }

    $(function () {
        var $current_target;
        $('.form-ajax').each(ajaxifyForm);
        $('.link-ajax').each(ajaxifyLink);

        $('.link-ajax .fa-pause').parent(".btn").mouseenter(function () {
            $(this).find('.fa').removeClass('fa-pause').addClass('fa-play');
        }).mouseleave(function () {
            $(this).find('.fa').addClass('fa-pause').removeClass('fa-play');
        });
        $('.link-ajax .fa-stop').parent(".btn").mouseenter(function () {
            $(this).find('.fa').removeClass('fa-stop').addClass('fa-play');
        }).mouseleave(function () {
            $(this).find('.fa').addClass('fa-stop').removeClass('fa-play');
        });
        $('.api-btn').click(userAPIAccess);
        $('.sessions-search-switch').click(toggleSessionSearch);
        if ($(".hidden_debug_message").length > 0) {
            $(".show_hidden_debugging_messages").click(function () {
                $('.hidden_debug_message').toggleClass("hidden");
                return false;
            });
            $(".show_hidden_debugging_messages").attr('disabled', false);
        }
        $('abbr.abbreviated_session').click(function ()
        {
            if ($(this).text() !== $(this).data("full-session")) {
                $(this).text($(this).data("full-session"));
            } else {
                $(this).text($(this).data("full-session").substr(0, 10) + "…");
            }
        });
        if ($(".download_r_code").length > 0) {
            $(".download_r_code").click(function () {
                return download_next_textarea(this);
            });
        }

        $('.removal_modal').on('show.bs.modal', function (e) {
            $current_target = $(e.relatedTarget);
            var $modal = $(this);
            $current_target.parents("tr").css("background-color", "#ee5f5b");
            $(this).find('.danger').attr('href', $current_target.data('href'));
            ajaxifyLink(1, $(this).find('.danger'));
            $(this).find('.danger').click(function (e) {
                $current_target.css("color", "#ee5f5b");
                if ($modal.hasClass('refresh_on_success')) {
                    window.setTimeout(function () {
                        document.location.reload(true);
                    }, 200);
                }
                $modal.modal("hide");

            });
        }).on("hide.bs.modal", function (e) {
            $current_target.parents("tr").css("background-color", "transparent");
        });
        $('a.delete-run-session').bind('click', deleteUserSession);
        $('a.remind-run-session').bind('click', remindUserSession);
    });
}(jQuery));