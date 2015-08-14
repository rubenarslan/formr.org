$(function(){
	"use strict";
    var $current_target;
	$('.form-ajax').each(ajaxifyForm);
	$('.link-ajax').each(ajaxifyLink);
	$('.api-btn').click(userAPIAccess);
	
	$('abbr.abbreviated_session').click(function ()
	{
		if($(this).text() !== $(this).data("full-session")) {
			$(this).text( $(this).data("full-session") );
		} else {
			$(this).text( $(this).data("full-session").substr(0,10) + "…" );
		}
	});
	
    $('#confirm-delete').on('show.bs.modal', function(e) {
        $current_target = $(e.relatedTarget);
        var $modal = $(this);
        $current_target.parents("tr").css("background-color","#ee5f5b");
        $(this).find('.danger').attr('href', $current_target.data('href'));
        ajaxifyLink(1,$(this).find('.danger'));
        $(this).find('.danger').click(function(e) {
            $current_target.css("color","#ee5f5b");
            $modal.modal("hide");
        });
    }).on("hide.bs.modal", function(e) {
        $current_target.parents("tr").css("background-color","transparent");
//        $current_target = null;
    });

	function ajaxifyLink(i,elm) {
	    $(elm).click(function(e)
	    {
	    	e.preventDefault();
	        var $this = $(this);
	        var old_href = $this.attr('href');
	        if(old_href === '') return false;
	        $this.attr('href','');

	    	$.ajax(
	    		{
	                type: "GET",
	                url: old_href,
	    			dataType: 'html',
	    		})
	    		.done($.proxy(function(data)
	    		{
	                $(this).attr('href',old_href);
	                if(!$(this).hasClass("danger"))
	                    $(this).css('color','green');
	    		},this))
	            .fail($.proxy(function(e, x, settings, exception) {
	                $(this).attr('href',old_href);
	                ajaxErrorHandling(e, x, settings, exception);
	            },this));
	    	return false;
	    });
	}

	function ajaxifyForm(i,elm) {
	    $(elm).submit(function(e)
	    {
	    	e.preventDefault();
	        var $this = $(this);
	        var $submit = $this.find('button.btn');
	        $submit.attr('disabled',true);

	    	$.ajax(
			{
	            type: $this.attr('method'),
	            url: $this.attr('action'),
	            data: $this.serialize(),
				dataType: 'html',
			})
			.done($.proxy(function(data)
			{
	            $submit.attr('disabled',false);
	            $submit.css('color','green');
				$('.main_body').prepend(data);
			},this))
	        .fail($.proxy(function(e, x, settings, exception) {
	            $submit.attr('disabled',false);
	            ajaxErrorHandling(e, x, settings, exception);
	        },this));
	    	return false;
	    });
	}

	function userAPIAccess(e) {
		var userId = parseInt($(this).data('user'), 11);
		var userEmail = $(this).data('email');
		if (!userId || !userEmail) {
			return;
		}

		var data = {user_id: userId, user_email: userEmail, user_api: true, api_action: 'get'};
		postdata(saAjaxUrl, data, function(response) {
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
			$modal.find('.api-create').click(function() {
				if (!confirm("Are you sure?"))  return;
				var request = {user_id: meta.user_id, user_email: meta.user_email, user_api: true, api_action: 'create'};
				postdata(saAjaxUrl, request, function(response) {
					if (response && response.success) {
						$modal.modal('hide');
						userAPIModal(response.data, meta);
					}
				});
			});

			$modal.find('.api-change').click(function() {
				if (!confirm("Are you sure?")) return;
				var request = {user_id: meta.user_id, user_email: meta.user_email, user_api: true, api_action: 'change'};
				postdata(saAjaxUrl, request, function(response) {
					if (response && response.success) {
						$modal.modal('hide');
						userAPIModal(response.data, meta);
					}
				});
			});

			$modal.find('.api-delete').click(function() {
				if (!confirm("Are you sure?")) return;
				var request = {user_id: meta.user_id, user_email: meta.user_email, user_api: true, api_action: 'delete'};
				postdata(saAjaxUrl, request, function(response) {
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

	function postdata(url, data, callback, type) {
		type = type || 'json';
		$.ajax({
			type: 'POST',
			url: url,
			data: data,
			dataType: type,
			success: function(response, code, jqxhr) {
				callback(response);
			},
			error: function(jqxhr, errText) {
				$('.main_body').prepend(errText);
			},
			beforeSend: function(jqxhr) {
				//alert(this.url);
			}
		});
	}
});