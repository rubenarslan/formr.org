import {
    bootstrap_modal, 
    ajaxErrorHandling, 
    download_next_textarea, 
    getHTMLTemplate, 
    bootstrap_spinner  
} from './main.js';

(function ($) {
    "use strict";
    function ajaxifyLink(i, elm) {
        $(elm).click(function (e) {
            e.preventDefault();
            var $this = $(this);
            var old_href = $this.attr('href');
            if (old_href === '')
                return false;
            $this.attr('href', '');

            $.ajax({
                type: "GET",
                url: old_href,
                dataType: 'html'
            }).done($.proxy(function (data) {
                var $this = $(this);
                $this.attr('href', old_href);
                if (!$this.hasClass("danger")) {
                    $this.css('color', 'green');
                }

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
            }, this)).fail($.proxy(function (e, x, settings, exception) {
                $(this).attr('href', old_href);
                ajaxErrorHandling(e, x, settings, exception);
            }, this));
            return false;
        });
    }

    function ajaxifyForm(i, elm) {
        $(elm).submit(function (e) {
            e.preventDefault();
            var $this = $(this);
            var $submit = $this.find('button[type=submit].btn');
            $submit.attr('disabled', true);

            $.ajax({
                type: $this.attr('method'),
                url: $this.attr('action'),
                data: $this.serialize(),
                dataType: 'html',
            }).done($.proxy(function (data) {
                $submit.attr('disabled', false);
                $submit.css('color', 'green');
                $('.alerts-container').prepend(data);

                if ($submit.hasClass('refresh_on_success')) {
                    document.location.reload(true);
                }
            }, this)).fail($.proxy(function (e, x, settings, exception) {
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

    function userDelete(e) {
        /*jshint validthis:true */
        
        var userId = parseInt($(this).data('user'), 10);
        var userEmail = $(this).data('email');
        if (!userId || !userEmail) {
            return;
        }

        var data = {user_id: userId, user_email: userEmail, user_delete: true};
        var $modal = $($.parseHTML(getHTMLTemplate('tpl-user-delete', {user: ' (' + data.user_email + ')'})));
        $modal.on('shown.bs.modal', function () {
            $modal.find('.user-delete').click(function () {
                postdata(saAjaxUrl, data, function (response) {
                    if (response && response.success) {
                        $modal.modal('hide');
                        document.location.reload(true);
                    }

                });
            });

        }).on('hidden.bs.modal', function () {
            $modal.remove();
        }).modal('show');

    }

    function verifyEmailManually(e) {
        /*jshint validthis:true */
        
        var userId = parseInt($(this).data('user'), 10);
        var userEmail = $(this).data('email');
        if (!userId || !userEmail) {
            return;
        }
        var $btn = $(this);

        var data = {user_id: userId, user_email: userEmail, verify_email_manually: true};
        postdata(saAjaxUrl, data, function (response) {
            if (response && response.success) {
                $btn.css("border-color", "green");
                $btn.css("color", "green");
                $btn.off('click');
            }
        });
    }
    
    function addDefaultEmailAccess(e) {
        /*jshint validthis:true */
        
        var userId = parseInt($(this).data('user'), 10);
        var userEmail = $(this).data('email');
        if (!userId || !userEmail) {
            return;
        }
        var $btn = $(this);

        var data = {user_id: userId, user_email: userEmail, add_default_email_access: true};
        postdata(saAjaxUrl, data, function (response) {
            if (response && response.success) {
                $btn.css("border-color", "green");
                $btn.css("color", "green");
                $btn.off('click');
            }
        });
    }
    
    function deleteUserSession(e) {
        /*jshint validthis:true */
        var $btn = $(this);
        var $modal = $($.parseHTML(getHTMLTemplate('tpl-delete-run-session', {action: $btn.data('href'), session: $btn.data('session')})));
        var $parent_tr = $btn.parents('tr');
        $btn.css("border-color", "#ee5f5b");
        $btn.css("color", "#ee5f5b");

        $modal.find('form').each(ajaxifyForm).submit(function () {
            $parent_tr.css("background-color", "#ee5f5b");
            $modal.modal('hide');
        });
        $modal.on('hidden.bs.modal', function () {
            $modal.remove();
            $btn.css("border-color", "black");
            $btn.css("color", "black");
        }).modal('show');

    }

	function deleteUserUnitSession(e) {
		/*jshint validthis:true */
        var $btn = $(this);
		var $modal = $($.parseHTML(getHTMLTemplate('tpl-confirmation',{
			content: $btn.data('msg'),
			yes_url: $btn.data('href'),
			no_url: "#"
		})));
		
		$modal.on('shown.bs.modal', function () {
            $modal.find('.btn-yes').click(function () {
                var href = $btn.data('href');
                $(this).append(bootstrap_spinner());
                postdata(href, {'ajax': true}, function (response) {
                    $modal.modal('hide');
                    var $bm = bootstrap_modal('Activity Deleted', response, 'tpl-feedback-modal');
					$bm.on('hidden.bs.modal', function () {
						$modal.remove();
						document.location.reload(true);
					}).modal('show');
                }, 'html');
            });
        }).on('hidden.bs.modal', function () {
            $modal.remove();
        }).modal('show');
	}

    function remindUserSession(e) {
        /*jshint validthis:true */
        var $btn = $(this);
        var $modal = $($.parseHTML(getHTMLTemplate('tpl-remind-run-session', {action: $btn.data('href'), session: $btn.data('session')})));
        var $parent_tr = $btn.parents('tr');
        //$btn.css("border-color", "#ee5f5b");
        //$btn.css("color", "#ee5f5b");

        $modal.on('shown.bs.modal', function () {
            $modal.find('.send').click(function () {
                var href = $btn.data('href'), reminder = $(this).data('reminder');
                $(this).append(bootstrap_spinner());
                postdata(href, {reminder: reminder}, function (response) {
                    $modal.modal('hide');
                    bootstrap_modal('Send Reminder', response, 'tpl-feedback-modal').modal('show');

                    $btn.css("border-color", "green");
                    $btn.css("color", "green");
                }, 'html');
            });
        });

        $modal.on('hidden.bs.modal', function () {
            $modal.remove();
            //$btn.css("border-color", "black");
            //$btn.css("color", "black");
        });//.modal('show');
        $.get($btn.data('href'), {session: $btn.data('session'), get_count: true}, function (response, textSatus, jqXH) {
            $modal.find('.reminder-row-count').text('(0)');
            for (var unit_id in response) {
                var count = response[unit_id];
                $modal.find('.reminder-row-count-' + unit_id).text(' (' + count + ')');
            }
            $modal.modal('show');
        });
    }

    function postdata(url, data, callback, type, errCallback) {
        type = type || 'json';
        errCallback = errCallback || function () {
        };
        $.ajax({
            type: 'POST',
            url: url,
            data: data,
            dataType: type,
            success: function (response, code, jqxhr) {
                callback(response);
            },
            error: function (jqxhr, errText) {
                $('.alerts-container').prepend(errText);
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

    function doBulkAction(e) {
        /*jshint validthis:true */
        var $btn = $(this);
        var sessions = [];
        $('input.ba-select-session').each(function () {
            if ($(this).is(':checked')) {
                sessions.push($(this).val());
            }
        });
        if (sessions.length && typeof (bulkActions[$btn.data('action')]) === 'function') {
            bulkActions[$btn.data('action')]($btn.parents('form').attr('action'), sessions);
        }
    }

    var bulkActions = {
        toggleTest: function (url, sessions) {
            var $modal = $($.parseHTML(getHTMLTemplate('tpl-confirmation', {
                'content': '<h4>Are you sure you want to perform this action?</h4>'
            })));
            $modal.on('shown.bs.modal', function () {
                $modal.find('.btn-yes').click(function (e) {
                    $(this).append(bootstrap_spinner());
                    postdata(url, {action: 'toggleTest', 'sessions': sessions}, function (response) {
                        $modal.modal('hide');
                        if (response.success) {
                            document.location = url.replace('ajax_user_bulk_actions', 'user_overview');
                            return;
                        }
                        $(this).find('.fa-spin').remove();
                        bootstrap_modal('Error', response.error, 'tpl-feedback-modal').modal('show');
                    }, 'json');
                });
                $modal.find('.btn-no').click(function (e) {
                    $modal.modal('hide');
                });
            }).on('hidden.bs.modal', function () {
                $modal.remove();
            }).modal('show');
        },
        sendReminder: function (url, sessions) {
            var $modal = $($.parseHTML(getHTMLTemplate('tpl-remind-run-session', {action: url, session: null})));

            $modal.on('shown.bs.modal', function () {
                $modal.find('.send').click(function () {
                    var reminder = $(this).data('reminder');
                    $(this).append(bootstrap_spinner());
                    postdata(url, {action: 'sendReminder', 'sessions': sessions, reminder: reminder}, function (response) {
                        $modal.modal('hide');
                        if (response.success) {
                            document.location = url.replace('ajax_user_bulk_actions', 'user_overview');
                            return;
                        }
                        bootstrap_modal('Error', response.error, 'tpl-feedback-modal').modal('show');
                    }, 'json');
                });
            }).on('hidden.bs.modal', function () {
                $modal.remove();
            }).modal('show');
        },
        deleteSessions: function (url, sessions) {
            var $modal = $($.parseHTML(getHTMLTemplate('tpl-confirmation', {
                'content': '<h4>Are you sure you want delete ' + sessions.length + ' session(s)?</h4>'
            })));
            $modal.on('shown.bs.modal', function () {
                $modal.find('.btn-yes').click(function (e) {
                    $(this).append(bootstrap_spinner());
                    postdata(url, {action: 'deleteSessions', 'sessions': sessions}, function (response) {
                        $modal.modal('hide');
                        if (response.success) {
                            document.location = url.replace('ajax_user_bulk_actions', 'user_overview');
                            return;
                        }
                        $(this).find('.fa-spin').remove();
                        bootstrap_modal('Error', response.error, 'tpl-feedback-modal').modal('show');
                    }, 'json');
                });
                $modal.find('.btn-no').click(function (e) {
                    $modal.modal('hide');
                });
            }).on('hidden.bs.modal', function () {
                $modal.remove();
            }).modal('show');
        },
        positionSessions: function (url, sessions) {
            var pos = parseInt($('select[name=ba_new_position]').val());
            if (isNaN(pos)) {
                alert('Bad position selected');
                return;
            }

            var $modal = $($.parseHTML(getHTMLTemplate('tpl-confirmation', {
                'content': '<h4>Are you sure you want push ' + sessions.length + ' session(s) to position <b>' + pos + '</b>?</h4>'
            })));
            $modal.on('shown.bs.modal', function () {
                $modal.find('.btn-yes').click(function (e) {
                    $(this).append(bootstrap_spinner());
                    postdata(url, {action: 'positionSessions', 'sessions': sessions, 'pos': pos}, function (response) {
                        $modal.modal('hide');
                        if (response.success) {
                            document.location = url.replace('ajax_user_bulk_actions', 'user_overview');
                            return;
                        }
                        $(this).find('.fa-spin').remove();
                        bootstrap_modal('Error', response.error, 'tpl-feedback-modal').modal('show');
                    }, 'json');
                });
                $modal.find('.btn-no').click(function (e) {
                    $modal.modal('hide');
                });
            }).on('hidden.bs.modal', function () {
                $modal.remove();
            }).modal('show');
        }
    };

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
        $('.verify-email-btn').click(verifyEmailManually);
        $('.add-email-btn').click(addDefaultEmailAccess);
        $('.del-btn').click(userDelete);
        $('.sessions-search-switch').click(toggleSessionSearch);


        $('abbr.abbreviated_session').click(function ()
        {
            if ($(this).text() !== $(this).data("full-session")) {
                $(this).text($(this).data("full-session"));
            } else {
                $(this).text($(this).data("full-session").substr(0, 10) + "…");
            }
        });

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
		$('a.delete-user-unit-session').bind('click', deleteUserUnitSession);
        $('a.remind-run-session').bind('click', remindUserSession);
        $('div.bulk-actions-ba').find('.ba').bind('click', doBulkAction);
    });
}(jQuery));