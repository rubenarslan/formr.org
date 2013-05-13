function RunUnit(content)
{
	this.block = $('<div class="run_unit row"></div>');
	this.init(content);
	this.block.insertBefore($('#run_dialog_choices'));
}
RunUnit.prototype.init = function(content)
{
	this.block.html($($.parseHTML(content))); // .html annoying but necessary, somewhere in here a clone where there should be none, appears
	this.position = this.block.find('.run_unit_position input.position')
	
	this.position_changed = false;
	this.position.change($.proxy(this.position_changes,this));
		
	this.dialog_inputs = this.block.find('div.run_unit_dialog input,div.run_unit_dialog  select, div.run_unit_dialog button, div.run_unit_dialog textarea,div.run_unit_position input.position');
	console.log(this.dialog_inputs);
	this.unit_id = this.dialog_inputs.filter('input[name=unit_id]').val();
	this.dialog_inputs.change($.proxy(this.changes,this));
	
	this.block.find('.hastooltip').tooltip();
	this.block.find('.select2').select2();
	
	this.unsavedChanges = false;
	this.save_button = this.block.find('a.unit_save');
	this.save_button.removeClass('btn-info').attr('disabled', 'disabled').text('Saved')
	.click($.proxy(this.save,this));
	
	this.block.find('button.from_days')
	.click(function(e)
	{
		e.preventDefault();
		var days = $(this).prev('input[type=number]').val();
		$(this).prev('input[type=number]').val( days * 60 * 24);
	});
	
	
	this.test_button = this.block.find('a.unit_test');
	this.test_button
	.click($.proxy(this.test,this));
	
	this.remove_button = this.block.find('a.remove_unit_from_run');
	this.remove_button
	.click($.proxy(this.removeFromRun,this))
	.mouseenter(function() {
		$(this).addClass('btn-danger');
	}).
	mouseleave(function(){
		$(this).removeClass('btn-danger');	
	});
};
RunUnit.prototype.position_changes = function (e) 
{
	this.position_changed = true;
	$reorderer.addClass('btn-info').removeAttr('disabled');
};
RunUnit.prototype.changes = function (e) 
{
	this.unsavedChanges = true;
	this.save_button.addClass('btn-info').removeAttr('disabled').text('Unsaved changes…');
};
RunUnit.prototype.test = function(e)
{
	e.preventDefault();
	var $unit = this.block;
	$.ajax(
		{
			url: $run_url + "/" + this.test_button.attr('href'),
			dataType: 'html',
			data: this.dialog_inputs.serialize(),
			method: 'GET'
		})
		.done($.proxy(function(data)
		{
			
			var $modal = $($.parseHTML('<!-- Modal --><div id="myModal" class="modal hide fade" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">  <div class="modal-header">    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>    <h3 id="myModalLabel">Test result</h3>  </div>  <div class="modal-body">' + data + '  </div>  <div class="modal-footer">    <button class="btn" data-dismiss="modal" aria-hidden="true">Close</button>  </div></div>'));
			$modal.modal('show');
		},this))
		.fail(ajaxErrorHandling);
	return false;
};

RunUnit.prototype.save = function(e)
{
	e.preventDefault();
	var $unit = this.block;
	$.ajax(
		{
			url: $run_url + "/" + this.save_button.attr('href'),
			dataType: 'html',
			data: this.dialog_inputs.serialize(),
			method: 'POST'
		})
		.done($.proxy(function(data)
		{
			
			if(! (data.indexOf('error') >= 0) ) 
			{
				$.proxy( this.init(data),this);
			}
			else
			{				
				var $alert = $(data);
				$('#edit_run').prepend( $alert);
				$alert[0].scrollIntoView(false);
			}
		},this))
		.fail(ajaxErrorHandling);
	return false;
};

RunUnit.prototype.removeFromRun = function(e)
{
	e.preventDefault();
	var $unit = this.block;
	
	$.ajax(
		{
			url: $run_url + "/" + this.remove_button.attr('href'),
			dataType: 'html',
			data: this.dialog_inputs.serialize(),
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
};

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
	
	
	

	
}
function bootstrap_alert(message,bold) {
	var $alert = $('<div class="alert alert-error"><button type="button" class="close" data-dismiss="alert">&times;</button><strong>' + (bold ? bold:'Problem' ) + '</strong> ' + message + '</div>');
	$('#edit_run').prepend( $alert);
	$alert[0].scrollIntoView(false);
}

function loadNextUnit(units)
{
	var data = units.shift();
	if(typeof data != 'undefined')
	{
		$.ajax( 
		{
			url: $run_url + '/ajax_get_unit',
			data: data,
			dataType:"html", 
			success:function (data, textStatus) 
			{
				$run_units.push(new RunUnit(data));
				loadNextUnit(units);
			}
		});
	}
}
$(document).ready(function () {
	if(typeof autosaveglobal == 'undefined') {
		lastSave = $.now(); // only set when loading the first time
		autosaveglobal = false;
	}
	$run_name = $('#run_name').val();
	$run_url = $('#edit_run').prop('action');
	$run_units = new Array();
	$('#edit_run').find('.hastooltip').tooltip();
	$('#edit_run').find('.select2').select2();
		
	var units = $.parseJSON($('#edit_run').attr('data-units'));
	loadNextUnit(units);
	
	$('#edit_run').find('a.add_run_unit')
	.click(function () 
	{
		$.ajax( 
		{
			url: $(this).attr('href'),
			dataType:"html",
			method: 'POST',
			data: {
				position: ($run_units.length + 1)
			}
		})
		.done(function(data)
		{
			if(! (data.indexOf('error') >= 0) ) 
			{
				$run_units.push(new RunUnit(data));
				loadNextUnit(units);
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
	
	$reorderer = $('#edit_run').find('a.reorder_units');
	
	pos_go = false;
	$reorderer
	.click(function (e) 
	{
		e.preventDefault();
		if(typeof $(this).attr('disabled') == 'undefined')
		{
			var positions = {};
			$($run_units).each(function(i,elm) {
				positions[elm.unit_id] = elm.position.val();
			});
			$.ajax( 
			{
				url: $(this).attr('href'),
				dataType:"html",
				method: 'POST',
				data: {
					position: positions
				}
			})
			.done(function(data)
			{
				if(! (data.indexOf('error') >= 0) ) 
				{
					pos_go = true;
					location.reload();
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
		}
	})
	.removeClass('btn-info').attr('disabled', 'disabled');
	
	
	
	window.onbeforeunload = function() {
		var message = false;
		$($run_units).each(function(i, elm)
		{
			if( (!pos_go && elm.position_changed) || elm.unsavedChanges)
			message = true;
			return false;
		});
		console.log(message);
		if (message ) {
			return 'You have unsaved changes.'
		}
	};
		
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