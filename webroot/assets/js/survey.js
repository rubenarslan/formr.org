(function() {
	// polyfill window.performance.now
	window.performance = window.performance || {};
	performance.now = (function() {
	  return performance.now       ||
	         performance.mozNow    ||
	         performance.msNow     ||
	         performance.oNow      ||
			performance.webkitNow;
	})();
	
	$(document).ready(function() {
		var survey = new Survey();
		$('form').on('change', function() { 
			survey.update();
		});
		$('form').change();
	});

	function Survey() {
		this.$progressbar = $('.progress .progress-bar');
		this.already_answered = this.$progressbar.data('already-answered');
		this.items_left = this.$progressbar.data('items-left');
		this.items_on_page = this.$progressbar.data('items-on-page');
		if(!$('.default_formr_button')[0]) this.items_on_page--; // we don't count submit buttons (but there is the special case of the default one)
//		console.log("this.items_on_page",this.items_on_page);
		this.hidden_but_rendered = this.$progressbar.data('hidden-but-rendered');
//		console.log("this.hidden_but_rendered",this.hidden_but_rendered);
		this.items_visible_on_page = this.items_on_page - this.hidden_but_rendered;
		this.percentage_minimum = this.$progressbar.data('percentage-minimum');
		this.percentage_maximum = this.$progressbar.data('percentage-maximum');
		this.form_inputs = {};
	
		// initialising special items
		// --------------------------
		webshim.ready('geolocation',function() {
			"use strict";
			$('.geolocator').click(function()
			{
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
			}).each(function()
			{
				$(this).closest('.input-group-btn.hidden').removeClass('hidden');
			});
		});
		webshim.ready('forms forms-ext dom-extend', function()
	    {
	        var radios = $('div.btn-radio button.btn');
	        radios.closest('div.btn-group').removeClass('hidden');
	        radios.closest('.controls').find('label[class!=keep-label]').addClass('hidden');
			radios.off('click').each(function() {
				var $btn = $(this);
          
				var is_checked_already = !!$('#'+$btn.attr('data-for')).prop('checked'); // couple with its radio button
				$btn.toggleClass('btn-checked', is_checked_already);
		
				var fc = new FastClick(this);
		
				webshim.addShadowDom($('#'+$btn.attr('data-for')), $btn.closest('div.btn-group'));
			}).click(function(event){
				var $btn = $(this);
				$('#'+$btn.attr('data-for')).prop('checked',true); // couple with its radio button
				var all_buttons = $btn.closest('div.btn-group').find('button.btn'); // find all buttons
				all_buttons.removeClass('btn-checked'); // uncheck all
				$btn.addClass('btn-checked'); // check this one
				$btn.change();
				return false;
			});


			$('div.btn-checkbox button.btn').off('click').click(function(event){
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

				var fc = new FastClick(this);

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
		
				$original_box.change(function()
				{
					var checked = !!$(this).prop('checked');
					var $btn = $('button.btn[data-for="'+ this.id +'"]');
					$btn.toggleClass('btn-checked', checked).find('i').toggleClass('fa-check', checked); // check this one
				})
				.change()
				.on('togglecheck',function()
				{
					var checked = !!$(this).prop('checked');
					$(this).prop('checked',!checked); // toggle check
					$(this).change(); // trigger change event to sync up
				});
		
				$btn.closest('div.btn-group').removeClass('hidden'); // show special buttons
				$original_box.closest('label').addClass('hidden'); // hide normal checkbox button
		
				var fc = new FastClick(this);
		
				webshim.addShadowDom($('#'+$btn.attr('data-for')), $btn.closest('div.btn-group'));
			});

			$('.item-number.counter input[type=number]').each(function() {
				var $input = $(this);
				$input.parents("span").hide();
				var btns = $('<div class="btn-group"><button class="btn btn-lg btn-down"><i class="fa fa-minus-circle"></i></button><button class="btn btn-lg btn-up"><i class="fa fa-plus-circle"></i></button></div>');
				btns.insertAfter($input.parents("span"));
				btns.find(".btn-down").click(function()
				{
					var val = 1;
					if($input.attr('value')) val = +$input.attr('value');
					if( $input.attr('min') < val ) 
					{
						$input.attr('value', val - 1 );   
						$input.change();
					}
					return false;
				});
				btns.find(".btn-up").click(function()
				{
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
					
//				var fcd = new FastClick(btns.find(".btn-down")); //broken
//				var fcu = new FastClick(btns.find(".btn-up"));
			});
			$("select.select2zone, .form-group.select2 select").each(function(i,elm)
			{
				"use strict";
				var slct = $(elm); 
				slct.select2();
				webshim.addShadowDom(slct, slct.select2("container"));
			});
			$(".select2pills select").each(function(i,elm)
			{
				"use strict";
				var slct = $(elm); 
				slct.select2({
					width: "element",
					dropdownCssClass: "bigdrop", // apply css that makes the dropdown taller
					maximumSelectionSize: slct.data('select2maximumSelectionSize'),
					maximumInputLength: slct.data('select2maximumInputLength'),
					formatResult: function(pill) {
						if(pill.id !== '')
						{
							var markup = "<strong>" + pill.text + "</strong><br><img width='200px' alt='"+pill.text+"' src='assets/img/pills/" + pill.id + ".jpg'/>";
							return markup;
						} else
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
	
			$(".people_list textarea").each(function(i,elm)
			{
				"use strict";
			
				var slct = $(elm); 
				slct.select2({
					width: "element",
					height: "2000px",
					data: [],
					formatNoMatches: function(term)
					{
						if(term !== '') return "Füge '" + term + "' hinzu!";
						else return "Weitere Personen hinzufügen.";
					},
					tokenSeparators: ["\n"],
					separator: '\n',
					createSearchChoice:function(term, data)
					{ 
						if ($(data).filter(function() { 
							return this.text.localeCompare(term) === 0; 
						}).length === 0) 
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
							return this.text.localeCompare(term) === 0; 
						}).length === 0) 
						{
							term = term.replace(',',';');
							return {id:term, text:term};
						}
					},
					initSelection:function(element, callback)
					{
						var data;
						if(!!slct.data('select2multiple')) {
							var intermed = element.val().split(",");
							data = [];
							for(var e = 0; e < intermed.length; e++) {
								data.push( {id: intermed[e], text: intermed[e] } );
							}
						} else {
							data = {id: element.val(), text: element.val()};
						}
						$.each(slctdata, function(k, v) {
							if(v.id === element.val()) {
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
	    var relative_time = window.performance.now ? performance.now() : null;
	    $('form').find("input.item_shown, input.item_shown_relative, input.item_answered, input.item_answered_relative").change(function(e) { e.stopPropagation(); });
		$(".form-group:not([data-showif])").each(function(i,elm) // walk through all form elements that are automatically shown
		{
	        $(elm).find("input.item_shown").val(pageload_time);
	        $(elm).find("input.item_shown_relative").val(relative_time);
	    });
		
		$(".form-group").each(function(i, elm) { // initialise ever changed tracker
			if(typeof $(elm).data('ever-changed') == "undefined") {
				$(elm).data('ever-changed', false);
				$(elm).change(function(){
				   $(this).data('ever-changed', true);
	               $(this).find("input.item_answered").val(mysql_datetime());
	               $(this).find("input.item_answered_relative").val(window.performance.now ? performance.now() : null);
				});
			}
		});
	}
	Survey.prototype.update = function (e) {
		this.getData();
		if(this.showIf()) // if the showif changes the available inputs, refresh data
			this.getData();
		this.getProgress();
	};
	Survey.prototype.getData = function () {
		var badArray = $('form').serializeArray(); // items that are valid for submission http://www.w3.org/TR/html401/interact/forms.html#h-17.13.2
		this.data = {};
		var survey = this;
		
		$.each(badArray, function(i, obj)
		{
			if(obj.name.indexOf('_') !== 0 && obj.name != "session_id") { // skip hidden items beginning with underscore (e.g _item_view)
				if(obj.name.indexOf('[]', obj.name.length - 2) > -1) obj.name = obj.name.substring(0,obj.name.length - 2);
		
				if(obj.value === "" && $("input[type=hidden][name='" + obj.name + "']").length === 1 && obj.value === $("input[type=hidden][name='" + obj.name + "']").attr("value")) {
					return true;
				}
		
				if(!survey.data[ obj.name ]) survey.data[obj.name] = obj.value;
				else survey.data[obj.name] += ", " + obj.value;
			}
		});
	
	};
	Survey.prototype.getProgress = function () {
		var survey = this;
		survey.items_answered_on_page = 0;
	
		$.each(this.data,function(name,value){
			if( ! survey.form_inputs[name] && !survey.form_inputs[name + "[]"] ) {
				survey.form_inputs[name] = document.getElementsByName(name).length ? 
					$(document.getElementsByName(name)) : 
					$(document.getElementsByName(name+"[]")).filter(":not(input[type=hidden])");
			}

			var visible_elm = survey.form_inputs[name];
		
//			console.log(visible_elm[0]);
//			console.log(visible_elm.parents(".form-group").data('ever-changed'));
		
			if(visible_elm[0] && value.length > 0 && visible_elm.parents(".form-group").data('ever-changed') && visible_elm[0].validity.valid) { // if it is valid like this, it gets half a point
							survey.items_answered_on_page += 1;
			}
		});
/*		console.log(survey.form_inputs);
		
		console.log("survey.already_answered",survey.already_answered);
		console.log('survey.items_answered_on_page',survey.items_answered_on_page);
		console.log('survey.items_visible_on_page',survey.items_visible_on_page);
		console.log('survey.items_visible_on_page',survey.items_visible_on_page);
		console.log('survey.items_left, ',survey.items_left);
*/		
		var prog_here = (survey.items_answered_on_page + survey.already_answered) / ( survey.items_visible_on_page + survey.items_left + survey.already_answered);
	
		var prog = prog_here * (survey.percentage_maximum - survey.percentage_minimum);  // the fraction of this survey that was completed is multiplied with the stretch of percentage that it was accorded
		prog = prog + survey.percentage_minimum;

		if(prog > survey.percentage_maximum) prog = survey.percentage_maximum;
	
		survey.$progressbar.css('width',Math.round(prog)+'%');
		survey.$progressbar.text(Math.round(prog)+'%');
		survey.change_events_set = true;
		return prog;
	};

	Survey.prototype.showIf = function(e)
	{
		var survey = this;
		var any_change = false;
		$(".form-group[data-showif]").each(function(i,elm) // walk through all form elements that are dynamically shown/hidden
		{
			var showif = $(elm).data('showif'); // get specific condition

			try
			{
				with(survey.data) // using the form data as the environment
				{
					var hide = ! eval(showif); // evaluate the condition
					if($(elm).hasClass('hidden') != hide) {
						any_change = true;
						$(elm).toggleClass('hidden', hide); // show/hide depending on evaluation
						$(elm).find('input,select,textarea').prop('disabled', hide); // enable/disable depending on evaluation
						$(elm).find('.select2-container').select2('enable',! hide); // enable/disable select2 in firefox 10, doesn't work via shadowdom
		                if(! hide) {
		                    $(elm).find("input.item_shown").val(mysql_datetime());
		                    $(elm).find("input.item_shown_relative").val(window.performance.now ? performance.now() : null);
							survey.items_visible_on_page++;
						} else {
							survey.items_visible_on_page--;
						}
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
		return any_change;
	};

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
}());