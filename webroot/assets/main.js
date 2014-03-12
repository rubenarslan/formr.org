$.webshims.setOptions("extendNative", false); 
$.webshims.setOptions('forms', {
	customDatalist: true,
	addValidators: true,
	waitReady: false,
    replaceValidationUI: true
});
$.webshims.setOptions('geolocation', {
	confirmText: '{location} wants to know your position. You will have to enter one manually if you decline.'
});
$.webshims.setOptions('forms-ext', {
		types: 'range date time number month color',
        customDatalist: true,
 	   replaceUI: {range: true}
});
$.webshims.polyfill('es5 forms forms-ext geolocation json-storage');
$.webshims.activeLang('de');


$(document).ready(function() {
    if($(".schmail").length == 1)
    {
        var schmail = $(".schmail").attr('href');
        schmail = schmail.replace("IMNOTSENDINGSPAMTO","").
        replace("that-big-googly-eyed-email-provider","gmail").
        replace(encodeURIComponent("If you are not a robot, I have high hopes that you can figure out how to get my proper email address from the above."),"").
        replace(encodeURIComponent("\r\n\r\n"),"");
        $(".schmail").attr('href',schmail);
    }
    
    new FastClick($(".navbar-toggle")[0]); // particularly annoying if this one doesn't fastclick
    
	$('*[title]').tooltip({
		container: 'body'
	});
	$('abbr.abbreviated_session').click(function()
    {
        $(this).text($(this).data("full-session"));
        $(this).off('click');
    });
    hljs.initHighlighting();
});


function bootstrap_alert(message,bold,where) 
{
	var $alert = $('<div class="row"><div class="col-md-6 col-sm-6 all-alerts"><div class="alert alert-danger"><button type="button" class="close" data-dismiss="alert">&times;</button><strong>' + (bold ? bold:'Problem' ) + '</strong> ' + message + '</div></div></div>');
	$alert.prependTo( $(where) );
	$alert[0].scrollIntoView(false);
}

function ajaxErrorHandling (e, x, settings, exception) 
{
	var message;
	var statusErrorMap = 
	{
		'400' : "Server understood the request but request content was invalid.",
		'401' : "You don't have access.",
		'403' : "You were logged out while coding, please open a new tab and login again. This way no data will be lost.",
		'404' : "Page not found.",
		'500' : "Internal Server Error.",
		'503' : "Server can't be reached."
	};
	if (e.status) 
	{
		message =statusErrorMap[e.status];
		if(!message)
			message= (typeof e.statusText !== 'undefined' && e.statusText !== 'error') ? e.statusText : 'Unknown error. Check your internet connection.';
	}
	else if(e.statusText==='parsererror')
		message="Parsing JSON Request failed.";
	else if(e.statusText==='timeout')
		message="The attempt to save timed out. Are you connected to the internet?";
	else if(e.statusText==='abort')
		message="The request was aborted by the server.";
	else
		message= (typeof e.statusText !== 'undefined' && e.statusText !== 'error') ? e.statusText : 'Unknown error. Check your internet connection.';

	bootstrap_alert(message, 'Error.','.main_body');
}