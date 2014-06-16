$(function(){
	$('.form-ajax').each(ajaxifyForm);
	$('.link-ajax').each(ajaxifyLink);
    
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