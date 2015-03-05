$(function(){
	"use strict";
    var $current_target;
	$('.form-ajax').each(ajaxifyForm);
	$('.link-ajax').each(ajaxifyLink);
	
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
	        $this = $(this);
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
	        $this = $(this);
	        $submit = $this.find('button.btn');
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
			},this))
	        .fail($.proxy(function(e, x, settings, exception) {
	            $submit.attr('disabled',false);
	            ajaxErrorHandling(e, x, settings, exception);
	        },this));
	    	return false;
	    });
	}
});