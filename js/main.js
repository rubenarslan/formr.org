$.webshims.setOptions('forms', {
       customDatalist: true,
	   waitReady: false,
	   addValidators: true
});
$.webshims.setOptions('forms-ext', {
       types: 'range date time number month color',
});
$.webshims.polyfill('es5 forms forms-ext');
$.webshims.activeLang('de');

$(document).ready(function() {
    $('.range_list_output').each(function () {
        var output = $('output', this);
//		console.log(output);	
        var change = function () {
            output.text($(this).prop('value') || '');
        };
        $('input[type="range"]', this)
            .on('input', change)
            .each(change);
    });
	// fixme: FOUCs for btnratings etc in IE8
	$('div.btn-radio button.btn').off('click').click(function(event){
		var $btn = $(this);
		$('#'+$btn.attr('data-for')).attr('checked',true); // couple with its radio button
		var all_buttons = $btn.closest('div.btn-group').find('button.btn'); // find all buttons
		all_buttons.removeClass('btn-checked'); // uncheck all
		$btn.addClass('btn-checked'); // check this one
		return false;
	}).each(function() {
		var $btn = $(this);
		$btn.closest('div.btn-group').removeClass('hidden'); // show special buttons
		$btn.closest('.controls').find('label[class!=keep-label]').addClass('hidden'); // hide normal radio buttons
	});
	
	$('div.btn-checkbox button.btn').off('click').click(function(event){
		var $btn = $(this);
		var checked = $('#'+$btn.attr('data-for')).attr('checked');
		$('#'+$btn.attr('data-for')).attr('checked',!checked); // couple with its radio button
		$btn.toggleClass('btn-checked',!checked); // check this one
		return false;
	}).each(function() {
		var $btn = $(this);
		$btn.closest('div.btn-group').removeClass('hidden'); // show special buttons
		$btn.closest('.controls').find('label').addClass('hidden'); // hide normal radio buttons
	});
	
	$('div.btn-check button.btn').off('click').click(function(event){
		var $btn = $(this);
		var checked = $('#'+$btn.attr('data-for')).attr('checked');
		$btn.find('i').toggleClass('icon-check',!checked).toggleClass('icon-check-empty',checked);
		$('#'+$btn.attr('data-for')).attr('checked',!checked); // couple with its radio button
		$btn.toggleClass('btn-checked',!checked); // check this one
		return false;
	}).each(function() {
		var $btn = $(this);
		$btn.closest('div.btn-group').removeClass('hidden'); // show special buttons
		$btn.closest('.controls').find('label').addClass('hidden'); // hide normal radio buttons
	});
	
	$('label.btn-remove').off('click').click(function(event){
		var $btn = $(this);
		var checked = $btn.find('input').attr('checked');
//		console.log(!checked);
		$btn.find('input').attr('checked',!checked); // couple with its radio button
		$btn.toggleClass('btn-checked',!checked); // check this one
		return false;
	}).each(function() {
		var $btn = $(this);
		$btn.addClass('btn'); // make buttons
		$btn.find('input').addClass('hidden'); // hide normal radio buttons
	});
	
	var pathArray = location.href.split( '/' );
	var protocol = pathArray[0];
	var host = pathArray[2];
	if(host=='localhost:8888') host = host + "/jena";
	var url = protocol + '//' + host + "/";
	
	$("input.select2add").each(function(i,elm)
	{
		var slct = $(elm); 
		slct.select2({
			createSearchChoice:function(term, data)
				{ 
					if ($(data).filter(function() 
					{ 
						return this.text.localeCompare(term)===0; 
					}).length===0) 
					{
						return {id:term, text:term};
					}
				},
		    initSelection : function (element, callback) {
				var data = {id: element.val(), text: element.val()};
				$.each(test_stats, function(k, v) {
				                       if(v.id ==  element.val()) {
				                           data = v;
				                           return false;
				                       } 
	            });
		        callback(data);
		    },
			data: $.parseJSON(slct.attr('data-select2add')), 
			multiple: !!slct.prop('multiple'), 
			allowClear: true,
		});
	});
	$('.select2place').select2({
	    ajax: {
	        url: url + "places/search",
	        dataType: 'json',
	        quietMillis: 100,
	        data: function (term, page) { // page is the one-based page number tracked by Select2
	            return {
	                term: term, //search term
	                page: page, // page number
	            };
	        },
	        results: function (data, page) {
	            var more = (page * 10) < data.total; // whether or not there are more results available

	            // notice we return the value of more so Select2 knows if more results can be loaded
	            return {results: data.places, more: more};
	        }
	    },
	    initSelection: function(element, callback) {
		   // the input tag has a value attribute preloaded that points to a preselected movie's id
		   // this function resolves that id attribute to an object that select2 can render
		   // using its formatResult renderer - that way the movie name is shown preselected
		   var id=$(element).val();
		   if (id!=="") {
			   $.ajax(url + "places/get/"+id, {
				   dataType: "json"
			   }).done(function(data) { 
				   callback(data[0]); 
			   }).fail(ajaxErrorHandling);
		   }
		},
		minimumInputLength: 3,
		formatInputTooShort: function (term, minLength) {
			return "Bitte geben Sie mindestens 3 Zeichen ein."
		},
		formatNoMatches: function (term) {
			return "Ort nicht gefunden, bitte geben Sie den nächstgelegenen größeren Ort ein."					
		}
	});
	
	$('.hastooltip').tooltip({
		container: 'body'
	});
});
function bootstrap_alert(message,bold) 
{
	var $alert = $('<div class="alert alert-error"><button type="button" class="close" data-dismiss="alert">&times;</button><strong>' + (bold ? bold:'Problem' ) + '</strong> ' + message + '</div>');
	$alert.insertAfter( $('nav') );
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