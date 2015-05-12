// Polyfill window.performance
(function(){

	if ("performance" in window == false) {
		window.performance = {};
	}
	// Polyfill Date.now for IE8
	Date.now = (Date.now || function () {
		return new Date().getTime();
	});

	if ("now" in window.performance == false) {
		var nowOffset = Date.now();
		if (performance.timing && performance.timing.navigationStart){
			nowOffset = performance.timing.navigationStart;
		}

		window.performance.now = function now(){
			return Date.now() - nowOffset;
		};
	}

})();

$(document).ready(function() {

	// initialising special items
	// --------------------------
	webshim.ready('geolocation',function() {
		"use strict";
		$('.geolocator').click(function() {
			var real_loc = $(this).closest('.controls').find('input[type=hidden]');
			var enter_loc = $(this).closest('.controls').find('input[type=text]');

			enter_loc.attr('placeholder','You can also enter your location manually');
			enter_loc.prop('readonly',false);
		
			navigator.geolocation.getCurrentPosition(
				function(pos) {
					real_loc.val(flatStringifyGeo(pos) );
					enter_loc.val("lat:"+ pos.coords.latitude +"/long:" + pos.coords.longitude );
					enter_loc.prop('readonly',true); // fixme: for some reason, if there is user entered text, FF doesn't show new JS-set text
				},
				function(err)
				{
					// error handling - this isn't called in firefox, when the user clicks "Not now".
				}
				/*
				todo: would be a nice options thing for geoloc
	 interface PositionOptions {
		attribute boolean enableHighAccuracy;
		attribute long timeout;
		attribute long maximumAge;
		};*/
			);
			return false;
		}).each(function() {
			$(this).closest('.input-group-btn.hidden').removeClass('hidden');
		});
	});
	webshim.ready('forms forms-ext dom-extend', function() {
        var radios = $('div.btn-radio button.btn');
        radios.closest('div.btn-group').removeClass('hidden');
        radios.closest('.controls').find('label[class!=keep-label]').addClass('hidden');
		radios.off('click').each(function() {
			var $btn = $(this);
          
			var is_checked_already = !!$('#'+$btn.attr('data-for')).prop('checked'); // couple with its radio button
			$btn.toggleClass('btn-checked', is_checked_already);
		
			new FastClick(this);
		
			webshim.addShadowDom($('#'+$btn.attr('data-for')), $btn.closest('div.btn-group'));
		}).click(function(event) {
			var $btn = $(this);
			$('#'+$btn.attr('data-for')).prop('checked',true); // couple with its radio button
			var all_buttons = $btn.closest('div.btn-group').find('button.btn'); // find all buttons
			all_buttons.removeClass('btn-checked'); // uncheck all
			$btn.addClass('btn-checked'); // check this one
			$btn.change();
			return false;
		});

		$('div.btn-checkbox button.btn').off('click').click(function(event) {
			var $btn = $(this);
			var checked = $('#'+$btn.attr('data-for')).prop('checked');
			$('#'+$btn.attr('data-for')).prop('checked',!checked); // couple with its radio button
			$btn.toggleClass('btn-checked',!checked); // check this one
			$('#'+$btn.attr('data-for')).change();
		
			return false;
		}).each(function() {
			var $btn = $(this);
			var is_checked_already = !!$('#'+$btn.attr('data-for')).prop('checked'); // couple with its radio button
			$btn.toggleClass('btn-checked', is_checked_already);
		
			$btn.closest('div.btn-group').removeClass('hidden'); // show special buttons
			$btn.closest('.controls').find('label').addClass('hidden'); // hide normal radio buttons

			new FastClick(this);

			webshim.addShadowDom($('#'+$btn.attr('data-for')), $btn.closest('div.btn-group'));
		});
        
		$('div.btn-check button.btn').off('click').click(function(event){
			"use strict";
			var $btn = $(this);
			$('#'+$btn.attr('data-for')).trigger("togglecheck"); // toggle the button
			return false;
		}).each(function() {
			"use strict";
			var $btn = $(this);
			var $original_box = $('#'+$btn.attr('data-for'));

			$original_box.change(function() {
				var checked = !!$(this).prop('checked');
				var $btn = $('button.btn[data-for="'+ this.id +'"]');
				$btn.toggleClass('btn-checked', checked).find('i').toggleClass('fa-check', checked); // check this one
			})
			.change()
			.on('togglecheck', function() {
				var checked = !!$(this).prop('checked');
				$(this).prop('checked',!checked); // toggle check
				$(this).change(); // trigger change event to sync up
			});
		
			$btn.closest('div.btn-group').removeClass('hidden'); // show special buttons
			$original_box.closest('label').addClass('hidden'); // hide normal checkbox button

			new FastClick(this);
			webshim.addShadowDom($('#'+$btn.attr('data-for')), $btn.closest('div.btn-group'));
		});

		$('.item-number.counter input[type=number]').each(function() {
			var $input = $(this)
			$input.parents("span").hide();
			var btns = $('<div class="btn-group"><button class="btn btn-lg btn-down"><i class="fa fa-minus-circle"></i></button><button class="btn btn-lg btn-up"><i class="fa fa-plus-circle"></i></button></div>')
			btns.insertAfter($input.parents("span"));
			btns.find(".btn-down").click(function() {
				var val = 1;
				if($input.attr('value')) val = +$input.attr('value');
				if( $input.attr('min') < val ) 
				{
					$input.attr('value', val - 1 );   
					$input.change();
				}
				return false;
			});
			btns.find(".btn-up").click(function() {
				var val = 1;
				if($input.attr('value')) val = +$input.attr('value');
				if( $input.attr('max') > val ) 
				{
					$input.attr('value', val + 1 );   
					$input.change();
				}
				return false;
			});
			webshim.ready("dom-extend", function(){
				webshim.addShadowDom($input, btns);
			});

			new FastClick(btns.find(".btn-down"));
			new FastClick(btns.find(".btn-up"));
		});

		$("select.select2zone, .form-group.select2 select").each(function(i,elm) {
			"use strict";
			var slct = $(elm); 
			slct.select2();
			webshim.addShadowDom(slct, slct.select2("container"));
		});

		$(".select2pills select").each(function(i,elm) {
			"use strict";
			var slct = $(elm); 
			slct.select2({
				width: "element",
				dropdownCssClass: "bigdrop", // apply css that makes the dropdown taller
				maximumSelectionSize: slct.data('select2maximumSelectionSize'),
				maximumInputLength: slct.data('select2maximumInputLength'),
				formatResult: function(pill) {
					if(pill.id != '') {
						var markup = "<strong>" + pill.text + "</strong><br><img width='200px' alt='"+pill.text+"' src='assets/img/pills/" + pill.id + ".jpg'/>";
						return markup;
					}

					return '';
				},
				formatSelection: function (pill) {
					return pill.text;
				},
				escapeMarkup: function (m) { return m; }
			}).on("change select2-open", function(e) {
				document.activeElement.blur();
			});
			webshim.addShadowDom(slct, slct.select2("container"));
		});

		$(".people_list textarea").each(function(i,elm) {
			"use strict";
			
			var slct = $(elm); 
			slct.select2({
				width: "element",
				height: "2000px",
				data: [],
				formatNoMatches: function(term)
				{
					if(term != '') return "Füge '" + term + "' hinzu!";
					else return "Weitere Personen hinzufügen.";
				},
				tokenSeparators: ["\n"],
				separator: '\n',
				createSearchChoice:function(term, data)
				{ 
					if ($(data).filter(function() { 
						return this.text.localeCompare(term)==0; 
					}).length===0) 
					{
						term = term.replace("\n",'; ');
						return {id:term, text:term};
					}
				},
				initSelection:function(element, callback)
				{
					var elements = element.val().split("\n");
					var data = [];
					for(var i = 0; i < elements.length; i++)
					{
						data.push( {id: elements[i], text: elements[i]});
					}
					callback(data);
				},
				maximumSelectionSize: 15,
				maximumInputLength: 50,
				formatResultCssClass: function(obj) { return "people_list_results"; },
				multiple: true, 
				allowClear: true,
				escapeMarkup: function (m) { return m; }
			}).removeClass("form-control");
			var plus = $("<span class='select2-plus'>+</span>");
			plus.insertBefore(slct.select2("container").find('.select2-search-field input'));
			webshim.addShadowDom(slct, slct.select2("container"));
		});
	
		$("input.select2add").each(function(i,elm)
		{
			var slct = $(elm); 
			if(slct.select2("container").hasClass("select2-container")) // is already select2
				return;
			var slctdata = $.parseJSON(slct.attr('data-select2add'));
			slct.select2({
				createSearchChoice:function(term, data)
				{ 
					if ($(data).filter(function() { 
						return this.text.localeCompare(term)==0; 
					}).length===0) 
					{
						term = term.replace(',',';');
						return {id:term, text:term};
					}
				},
				initSelection:function(element, callback)
				{
					var data = {id: element.val(), text: element.val()};
					$.each(slctdata, function(k, v) {
						if(v.id ==	element.val()) {
							data = v;
							return false;
						} 
					});
					callback(data);
				},
				maximumSelectionSize: slct.data('select2maximumSelectionSize'),
				maximumInputLength: slct.data('select2maximumInputLength'),
				data: slctdata, 
				multiple: !!slct.data('select2multiple'), 
				allowClear: true,
				escapeMarkup: function (m) { return m; }
			});
		
			webshim.addShadowDom(slct, slct.select2("container"));
		
		});
    });
	webshim.ready('forms forms-ext dom-extend form-validators',function() {
		webshim.addCustomValidityRule('always_invalid', function(elem, val){
			if(!$(elem).hasClass('always_invalid')){return;}
			return true;
		}, 'Cannot submit while there are problems with openCPU.');
		webshim.refreshCustomValidityRules();
	});
    
    var pageload_time = mysql_datetime();
    var relative_time = window.performance.now();
    $('form').find("input.item_shown, input.item_shown_relative, input.item_answered, input.item_answered_relative").change(function(e) { e.stopPropagation(); });
	$(".form-group:not([data-showif])").each(function(i,elm) // walk through all form elements that are automatically shown
	{
        $(elm).find("input.item_shown").val(pageload_time);
        $(elm).find("input.item_shown_relative").val(relative_time);
    });
    
	$('form').on('change', showIf);
	$('form').on('change', getProgress);
	$('form').change();
});

var $progressbar,change_events_set;
function getProgress() 
{
	"use strict";
	$progressbar = $('.progress .progress-bar');

//	var successful_controls = $('form').serializeArray(); 

	var badArray = $('form').serializeArray(); // items that are valid for submission http://www.w3.org/TR/html401/interact/forms.html#h-17.13.2
	var successful_controls = {};
	$.each(badArray, function(i, obj)
	{
		if(obj.name.indexOf('_') !== 0 && obj.name != "session_id") { // skip hidden items beginning with underscore (e.g _item_view)
			if(obj.name.indexOf('[]', obj.name.length - 2) > -1) obj.name = obj.name.substring(0,obj.name.length - 2);
				
			if(!successful_controls[ obj.name ]) successful_controls[obj.name] = obj.value;
			else successful_controls[obj.name] += ", " + obj.value;
		}
	});
	
	var already_answered = $progressbar.data('already-answered');
	var remaining_items = $progressbar.data('items-left');
	var percentage_minimum = $progressbar.data('percentage-minimum');
	var percentage_maximum = $progressbar.data('percentage-maximum');

	var items_answered_on_page = 0;
	var unanswered_page_items = 0;
	
	$.each(successful_controls,function(name,value){

		var elm_non_hidden = document.getElementsByName(name).length ? 
		  $(document.getElementsByName(name)) : 
		$(document.getElementsByName(name+"[]"));
		elm_non_hidden = elm_non_hidden.filter(":not(input[type=hidden])");
		
		if(typeof $(elm_non_hidden).parents(".form-group").data('ever-changed') == "undefined")
		{
			$(elm_non_hidden).parents(".form-group").data('ever-changed', false);
			$(elm_non_hidden).parents(".form-group").change(function(){
			   $(this).data('ever-changed', true);
               $(this).find("input.item_answered").val(mysql_datetime());
               $(this).find("input.item_answered_relative").val(window.performance.now());
			});
		}
		
		var elm = elm_non_hidden[0];
		if(elm)
		{
//			var pre = items_answered_on_page;
			if(value.length > 0) // if it's not empty, you get  //  || parseFloat(elm.value)
			{
				if($(elm).parents(".form-group").data('ever-changed') || elm_non_hidden.attr('type') == "hidden") //elm.value == elm_non_hidden.defaultValue) 
			   {
		   			items_answered_on_page += 0.5; // half a point for changing the default value
					
					if(elm.validity.valid) { // if it is valid like this, it gets half a point
						items_answered_on_page += 0.5;
					}
					else
					{
						unanswered_page_items += 0.5;
					}
				}
				else
				{
					unanswered_page_items += 1;
				}
				// cases: 
				// range, default: 0 + 0.5 = 0.05
				// email, "text": 0.5 + 0 = 0.5
				// text, "": 0 + 0
				// text, "xx": 0.5 + 0.5 = 1
			}
			else
			{
				unanswered_page_items += 1;
			}
//			console.log(name, value, (items_answered_on_page - pre));
			
		}
	});
//	console.log(already_answered, items_answered_on_page, unanswered_page_items, remaining_items);
	var prog_here = (items_answered_on_page + already_answered) / (remaining_items + unanswered_page_items + items_answered_on_page + already_answered);
	
	var prog = prog_here * (percentage_maximum - percentage_minimum);  // the fraction of this survey that was completed is multiplied with the stretch of percentage that it was accorded
	prog = prog + percentage_minimum;

	if(prog > percentage_maximum) prog = percentage_maximum;
	
	$progressbar.css('width',Math.round(prog)+'%');
	$progressbar.text(Math.round(prog)+'%');
	change_events_set = true;
	return prog;
}


function showIf(e)
{
	var badArray = $('form').serializeArray(); // get data live for current form
	var subdata = {};
	$.each(badArray, function(i, obj)
	{
		if(obj.name.indexOf('_') !== 0 && obj.name != "session_id") { // skip hidden items beginning with underscore (e.g _item_view)
			if(obj.name.indexOf('[]', obj.name.length - 2) > -1) obj.name = obj.name.substring(0,obj.name.length - 2);
		
			if(obj.value === "" && $("input[type=hidden][name='" + obj.name + "']").length === 1 && obj.value === $("input[type=hidden][name='" + obj.name + "']").attr("value")) {
				return true; // do not count the default values, that we just put in hidden inputs to make PHP understand that a radio or checkbox was submitted 
			}
		
			if(!subdata[ obj.name ]) subdata[obj.name] = obj.value;
			else subdata[obj.name] += ", " + obj.value;
		}
	});
	
	$(".form-group[data-showif]").each(function(i,elm) // walk through all form elements that are dynamically shown/hidden
	{
		var showif = $(elm).data('showif'); // get specific condition

		// primitive R to JS translation
		showif = showif.replace(/current\(\s*(\w+)\s*\)/g, "$1"); // remove current function
		showif = showif.replace(/tail\(\s*(\w+)\s*, 1\)/g, "$1"); // remove current function, JS evaluation is always in session
		// all other R functions may break
		showif = showif.replace(/"/g, "'"); // double quotes to single quotes
		showif = showif.replace(/(^|[^&])(\&)([^&]|$)/g, "$1&$3"); // & operators, only single ones need to be doubled
		showif = showif.replace(/(^|[^|])(\|)([^|]|$)g/, "$1&$3"); // | operators, only single ones need to be doubled
		showif = showif.replace(/FALSE/g, "false"); // uppercase, R, FALSE, to lowercase, JS, false
		showif = showif.replace(/TRUE/g, "true"); // uppercase, R, TRUE, to lowercase, JS, true
		showif = showif.replace(/\s*\%contains\%\s*([a-zA-Z0-9_'"]+)/g,".indexOf($1) > -1");
		showif = showif.replace(/\s*stringr::str_length\(([a-zA-Z0-9_'"]+)\)/g,"$1.length");
		
		try
		{
			with(subdata) // using the form data as the environment
			{
				var hide = ! eval(showif); // evaluate the condition
				$(elm).toggleClass('hidden', hide); // show/hide depending on evaluation
				$(elm).find('input,select,textarea').prop('disabled', hide); // enable/disable depending on evaluation
				$(elm).find('.select2-container').select2('enable',! hide); // enable/disable select2 in firefox 10, doesn't work via shadowdom
                if(! hide) {
                    $(elm).find("input.item_shown").val(mysql_datetime());
                    $(elm).find("input.item_shown_relative").val(window.performance.now());
                }
			}
		}
		catch(e)
		{
			if(window.console) console.log("JS showif failed",showif, e,  $(elm).find('input').attr('name'));
		}
		finally
		{
			return;
		}
	});
}

function flatStringifyGeo(geo) {
	"use strict";
	var result = {};
	result.timestamp = geo.timestamp;
	var coords = {};
	coords.accuracy = geo.coords.accuracy;
	coords.altitude = geo.coords.altitude;
	coords.altitudeAccuracy = geo.coords.altitudeAccuracy;
	coords.heading = geo.coords.heading;
	coords.latitude = geo.coords.latitude;
	coords.longitude = geo.coords.longitude;
	coords.speed = geo.coords.speed;
	result.coords = coords;
	return JSON.stringify(result);
}
function mysql_datetime() {
    return (new Date()).toISOString().slice(0, 19).replace('T', ' ');
}