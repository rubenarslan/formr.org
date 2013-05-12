function RunUnit(content)
{
	this.block = $($.parseHTML(content));
	var existing = $run_units.length;
	this.position = this.block.find('.run_unit_position input.position')
	if(this.position.val()=='')
		this.position.val( (existing+1) );
	
	activateInputs(this.block);
	this.block.insertPolyfillBefore($('#run_dialog_choices'));
}

function toggleAutosave() {
	autosaveglobal = !autosaveglobal;
	icon = autosaveglobal ? ' <i class="icon-refresh icon-white"></i>' : '';
	$("#toggle_autosave").button('toggle').html('Toggle Autosave' + icon);
	return false;
}
function unsavedChanges () {
	$('#save_run').addClass('btn-info').removeAttr('disabled').text('Unsaved changes…');
	if(autosaveglobal) {
		if( ($.now() - lastSave ) > 7000) {
			save_run();
		}
	}
}
function activateInputs ($container) {
	formelms = $('#edit_run input[type=text]:not([class*="select2-input"]),	#edit_run input[type=number],			#edit_run input[type=search],	#edit_run select,	#edit_run input[type=radio],	#edit_run input[type=checkbox],	#edit_run textarea').filter(':visible').filter(':not([class*="hidden"])');
	
	$container.find('.hastooltip').tooltip();
	$container.find('.select2').select2();
	
	$container.find('a.unit_save').click(function(){
		var $run_unit = $(this).closest('div.run_unit');
		var $elms = $run_unit.find('input, select, button, textarea');

		$.ajax(
			{
				url: $run_url + "/" + $(this).attr('href'),
				dataType: 'html',
				data: $elms.serialize(),
				method: 'POST'
			})
			.done(function(data)
			{
				if(! (data.indexOf('error') >= 0) ) 
				{
					$run_unit.html(data);
				}
				else
				{				
					var $alert = $(data);
					$('#edit_run').prepend( $alert);
					$alert[0].scrollIntoView(false);
				}
			})
			.fail(ajaxErrorHandling);
		return false;
	});
	
	$container.find('a.remove_unit_from_run').click(function(){ // , a.delete_unit
		var $unit = $(this).closest('div.run_unit');
		var $elms = $(this).closest('div.run_unit').find('input, select, button, textarea');
		
		$.ajax(
			{
				url: $run_url + "/" + $(this).attr('href'),
				dataType: 'html',
				data: $elms.serialize(),
				method: 'POST'
			})
			.done(function(data)
			{
				if(! (data.indexOf('error') >= 0) ) 
				{
					$unit.html(data);
				}
				else
				{				
					var $alert = $(data);
					$('#edit_run').prepend( $alert);
					$alert[0].scrollIntoView(false);
				}
			})
			.fail(ajaxErrorHandling);
		return false;
	});
	
	$container.find('a.add_run_unit')
	.click(function () 
	{
		var icon = $(this).find('i');
		$.ajax( 
		{
			url: $(this).attr('href'),
			dataType:"html"
		})
		.done(function(data)
		{
			if(! (data.indexOf('error') >= 0) ) 
			{
				$run_units.push(new RunUnit(data));
			}			
			else
			{				
				var $alert = $(data);
				$('#edit_run').prepend( $alert);
				$alert[0].scrollIntoView(false);
			}
		})
		.fail(ajaxErrorHandling);
		return false;
	});
	
}
function save_run() {
	$all_saves.each(function()
	{
		$(this).click();
	})
	$('#save_run').removeClass('btn-info').attr('disabled', 'disabled').text('Saved');
	lastSave = $.now();
	/*
			var $alert = $(data);
			$('#main-content').prepend( $alert.fadeIn() );
			$alert[0].scrollIntoView();
	*/
}
function bootstrap_alert(message,bold) {
	var $alert = $('<div class="alert alert-error"><button type="button" class="close" data-dismiss="alert">&times;</button><strong>' + (bold ? bold:'Problem' ) + '</strong> ' + message + '</div>');
	$('#edit_run').prepend( $alert);
	$alert[0].scrollIntoView(false);
}


$(document).ready(function () {
	if(typeof autosaveglobal == 'undefined') {
		lastSave = $.now(); // only set when loading the first time
		autosaveglobal = false;
	}
	$run_name = $('#run_name').val();
	$run_url = $('#edit_run').prop('action');
	$run_units = new Array();
	
	activateInputs($('#edit_run'));
	
	var units = $.parseJSON($('#edit_run').attr('data-units'));
	for(var i=0; i < units.length; i++)
	{
		$.ajax( 
		{
			url: $run_url + '/ajax_get_unit',
			data: units[i],
			dataType:"html", 
			success:function (data, textStatus) 
			{
				$run_units.push(new RunUnit(data));
			}
		});
	}
	
	
	
/*	window.onbeforeunload = function() {
		if ( $('#save_run').text() != 'Saved' ) {
			return 'You have unsaved changes.'
		}
	};
		*/
});
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
			message= (typeof e.statusText != 'undefined' && e.statusText != 'error') ? e.statusText : 'Unknown error. Check your internet connection.';
	}
	else if(e.statusText=='parsererror')
	    message="Parsing JSON Request failed.";
	else if(e.statusText=='timeout')
	    message="The attempt to save timed out. Are you connected to the internet?";
	else if(e.statusText=='abort')
	    message="The request was aborted by the server.";
	else
		message= (typeof e.statusText != 'undefined' && e.statusText != 'error') ? e.statusText : 'Unknown error. Check your internet connection.';

	bootstrap_alert(message, 'Fehler.');
}