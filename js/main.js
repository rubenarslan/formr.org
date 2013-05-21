$.webshims.polyfill('forms forms-ext');
$.webshims.setOptions('forms', {
       customDatalist: true
});
$(document).ready(function() {
    $('.range_list_output').each(function () {
        var output = $('output', this);
		console.log(output);	
        var change = function () {
            output.text($(this).prop('value') || '');
        };
        $('input[type="range"]', this)
            .on('input', change)
            .each(change);
    });
	
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
		$btn.closest('.controls').find('label').addClass('hidden'); // hide normal radio buttons
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
		console.log(!checked);
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
			return "Ort nicht gefunden, bitte geben Sie ihn selbst ein."					
		}
	});
	
	$('.hastooltip').tooltip();
});