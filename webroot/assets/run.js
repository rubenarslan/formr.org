function RunUnit(content)
{
	this.block = $('<div class="run_unit row"></div>');
	this.init(content);
	this.block.insertPolyfillBefore($('#run_dialog_choices'));
	hookUpAceToTextareas();
    
}
RunUnit.prototype.init = function(content)
{
	this.block.html($($.parseHTML(content))); // .html annoying but necessary, somewhere in here a clone where there should be none, appears
	this.position = this.block.find('.run_unit_position input.position');
	
	this.position_changed = false;
	this.position.change($.proxy(this.position_changes,this));
		
	this.dialog_inputs = this.block.find('div.run_unit_dialog input,div.run_unit_dialog select, div.run_unit_dialog button, div.run_unit_dialog textarea');
//	console.log(this.dialog_inputs);
	this.unit_id = this.dialog_inputs.filter('input[name=unit_id]').val();
    this.block.attr('id',"unit_"+this.unit_id);
	this.dialog_inputs.on('input change',$.proxy(this.changes,this));
	this.save_inputs = this.dialog_inputs.add(this.position);
	
	// todo: file bug report with webshims, oninput fires only onchange for number inputs
	
	this.block.find('.hastooltip').tooltip({
		container: 'body'
	});
	this.block.find('.select2').select2();
	
	this.unsavedChanges = false;
	this.save_button = this.block.find('a.unit_save');
	this.save_button.removeClass('btn-info').attr('disabled', 'disabled').text('Saved')
	.click($.proxy(this.save,this));
	
	this.block.find('button.from_days')
	.click(function(e)
	{
		e.preventDefault();
		var numberinput = $(this).closest('.input-group').find('input[type=number]');
		var days = numberinput.val();
		numberinput.val( days * 60 * 24).change();
	});
	
	
	this.test_button = this.block.find('a.unit_test');
	this.test_button
	.click($.proxy(this.test,this));
	
	this.remove_button = this.block.find('button.remove_unit_from_run');
	this.remove_button
	.click($.proxy(this.removeFromRun,this))
	.mouseenter(function() {
		$(this).addClass('btn-danger');
	}).
	mouseleave(function(){
		$(this).removeClass('btn-danger');	
	});
	hookUpAceToTextareas();
};
RunUnit.prototype.position_changes = function (e) 
{
	this.position_changed = true;
	this.position.parent().addClass('pos_changed');
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
    var old_text = this.test_button.text();
	this.test_button.attr('disabled',true).html(old_text + ' <i class="fa fa-spinner fa-spin"></i>');
    
	var $unit = this.block;
	$.ajax(
		{
			url: $run_url + "/" + this.test_button.attr('href'),
			dataType: 'html',
			data: this.save_inputs.serialize(),
			method: 'GET'
		})
		.done($.proxy(function(data)
		{
			
			var $modal = $($.parseHTML('<div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">                     <div class="modal-dialog">                         <div class="modal-content">                              <div class="modal-header">                                 <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>                                 <h3>Test result</h3>                             </div>                             <div class="modal-body">' + data + '  </div>                             <div class="modal-footer">                             <button class="btn" data-dismiss="modal" aria-hidden="true">Close</button>                         </div>                     </div>                 </div>'));

			$modal.modal('show').on('hidden.bs.modal',function() {
			    $modal.remove();
			});
            $(".opencpu_accordion").collapse({toggle:true});
            
        	this.test_button.html(old_text).removeAttr('disabled');
            var code_blocks = $modal.find('pre code');
            Array.prototype.forEach.call(code_blocks, hljs.highlightBlock);
//            $modal.find('#opencpu_accordion').on('hidden', function (event) {
//              event.stopPropagation()
//            });
		},this))
		.fail(ajaxErrorHandling);
	return false;
};

RunUnit.prototype.save = function(e)
{
	e.preventDefault();
    var old_text = this.save_button.text();
	this.save_button.attr('disabled',true).html(old_text + ' <i class="fa fa-spinner fa-spin"></i>');
    
    
	var $unit = this.block;
	$.ajax(
		{
			url: $run_url + "/" + this.save_button.attr('href'),
			dataType: 'html',
			data: this.save_inputs.serialize(),
			method: 'POST'
		})
		.done($.proxy(function(data)
		{
			
			if(data.indexOf('error') < 0) 
			{
				$.proxy( this.init(data),this);
			}
			else
			{				
				var $alert = $(data);
				$('#edit_run').prepend( $alert);
				$alert[0].scrollIntoView(false);
			}
//        	this.save_button.html(old_text).removeAttr('disabled');
            
		},this))
		.fail(ajaxErrorHandling);
	return false;
};

RunUnit.prototype.removeFromRun = function(e)
{
	e.preventDefault();
    $(".tooltip").hide();
	var $unit = this.block;
	$.ajax(
		{
			url: $run_url + "/" + this.remove_button.attr('href'),
			dataType: 'html',
			data: this.save_inputs.serialize(),
			method: 'POST'
		})
		.done(function(data)
		{
			if(data.indexOf('error') < 0) 
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


function loadNextUnit(units)
{
	var data = units.shift();
	if(typeof data !== 'undefined')
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
	if(typeof autosaveglobal === 'undefined') {
		lastSave = $.now(); // only set when loading the first time
		autosaveglobal = false;
	}
	$run_name = $('#run_name').val();
	$run_url = $('#edit_run').prop('action');
    $('#edit_run').submit(function(){ return false; });
	$run_units = [];
	$('#edit_run').find('.hastooltip').tooltip({
		container: 'body'
	});
	$('#edit_run').find('.select2').select2();
		
	var units = $.parseJSON($('#edit_run').attr('data-units'));
	loadNextUnit(units);
	
	$('#edit_run').find('a.run-toggle')
	.click(function () 
	{
		var on = (! $(this).hasClass('btn-checked') ) ? 1 : 0;
		var self = $(this);
 		$.ajax( 
		{
			url: self.attr('href'),
			dataType:"html",
			method: 'POST',
			data: {
				on: on
			}
		})
		.done(function(data)
		{
			if(data.indexOf('error') < 0) 
			{
				self.toggleClass('btn-checked',on);
			}
		})
		.fail(ajaxErrorHandling);
		return false;
	});
	
	
	$('#edit_run').find('a.add_run_unit')
	.click(function () 
	{
		var positions = $('.run_unit_position input:visible').map(function() { 
			return +$(this).val(); 
		}); // :visible in case of webshims. 
		var positions = $.makeArray(positions);
		var max = positions.slice().sort(function(x,y){ return x-y; }).pop(); // get maximum by sorting and popping the last elm. slice to copy (and later reuse) array
		$.ajax( 
		{
			url: $(this).attr('href'),
			dataType:"html",
			method: 'POST',
			data: 
			{
				position: max + 1
			}
		})
		.done(function(data)
		{
			if(data.indexOf('error') < 0) 
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
	
	$reorderer
	.click(function (e) 
	{
		e.preventDefault();
		if(typeof $(this).attr('disabled') === 'undefined')
		{
			var positions = {};
            var are_positions_unique = [];
            var pos;
            var dupes = false;
			$($run_units).each(function(i,elm) {
                pos = +elm.position.val();
                
                if($.inArray(pos,are_positions_unique)>-1)
                {
                	bootstrap_alert("You used the position "+pos+" more than once, therefore the new order could not be saved. <a href='#unit_"+elm.unit_id+"'>Click here to scroll to the duplicated position.</a>", 'Error.','.main_body');
                    dupes = true;
                    return;
                }
                else
                {
    				positions[elm.unit_id] = pos;                    
                    are_positions_unique.push(pos);
                }
			});
            if(!dupes)
            {
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
    				if(data.indexOf('error') < 0) 
    				{
    					$($run_units).each(function(i,elm) {
    						elm.position_changed = false;
    					});
    					$reorderer.removeClass('btn-info').attr('disabled', 'disabled');
    					var old_positions = $.makeArray($('.run_unit_position input:visible').map(function() { return +$(this).val(); }));
    					var new_positions = old_positions;
    					old_positions = old_positions.join(','); // for some reason I have to join to compare contents, otherwise annoying behavior with clones etc
    					new_positions.sort(function(x,y){ return x-y; }).join(',');
					
                        $reorderer.removeClass('btn-info').attr('disabled', 'disabled');
    					if(old_positions != new_positions)
    					{
    						location.reload();
    					} else
    					{
    						$('.pos_changed').removeClass('pos_changed');
    					}
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
		}
	});
	
	
	
	window.onbeforeunload = function() {
		var message = false;
		$($run_units).each(function(i, elm)
		{
			if(elm.position_changed || elm.unsavedChanges)
			{
				message = true;
				return false;
			}
		});
		if (message ) {
			return 'You have unsaved changes.'
		}
	};
		
});

// https://gist.github.com/duncansmart/5267653
// Hook up ACE editor to all textareas with data-editor attribute
function hookUpAceToTextareas () {
   $('textarea[data-editor]:visible').each(function () {
       var textarea = $(this);

       var mode = textarea.data('editor');

       var editDiv = $('<div>', {
           position: 'absolute',
           width: textarea.width(),
           height: textarea.height(),
           'class': textarea.attr('class')
       }).insertBefore(textarea);

//       textarea.css('visibility', 'hidden');
       textarea.css('display', 'none');

//       ace.require("ace/ext/language_tools");

       var editor = ace.edit(editDiv[0]);
       editor.setOptions({
           minLines: 5,
           maxLines: 30
       });
       editor.renderer.setShowGutter(false);
       editor.getSession().setValue(textarea.val());
       editor.getSession().setUseWrapMode(true);
       editor.getSession().setWrapLimitRange(42, 42);
//       editor.setOptions({
//           enableBasicAutocompletion: true
//       });
       editor.getSession().setMode("ace/mode/" + mode);
       editor.setTheme("ace/theme/textmate");
       editor.on('change', function(){
         textarea.val(editor.getSession().getValue());
         textarea.change();
       });
//       // copy back to textarea on form submit...
//       textarea.closest('form').submit(function () {
//           textarea.val(editor.getSession().getValue());
//       })

   });
}