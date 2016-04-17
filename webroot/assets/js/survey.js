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
	
	$(document).ready(function(e) {
		var survey = new Survey();
        if($("button.monkey").length > 0) {
            $("button.monkey").click(function() {
                survey.doMonkey(0);
                return false;
            }); 
            $("button.monkey").attr('disabled',false);
        }
		survey.update(e);
		$('form.main_formr_survey').on('change', function(e) { 
			survey.update(e);
		});
	});
	if($(".form-row.hidden").length > 0) {
        $(".show_hidden_items").click(function() {
			$('.form-row.hidden').removeClass("hidden");
            return false;
        }); 
        $(".show_hidden_items").attr('disabled',false);
    }
	
	function ButtonGroup(item) {
		this.$item = $(item);
		this.$button_group = this.$item.find(".btn-group");
		if(this.$item.hasClass("btn-checkbox")) {
			this.kind = "checkbox";
		} else if(this.$item.hasClass("btn-check")) {
			this.kind = "check";
		} else {
			this.kind = "radio";
		}
		this.$buttons = this.$button_group.find(".btn");
		this.$inputs = this.$item.find("input[id]");
		var group = this;
		this.$buttons.off('click').each(function() {
			var $btn = $(this),
				$input = group.$inputs.filter('#'+$btn.attr('data-for'));
			var is_checked_already = !!$input.prop('checked'); // couple with its radio button
			$btn.toggleClass('btn-checked', is_checked_already);
			webshim.addShadowDom($input, group.$button_group);
			
			// hammer time
			$btn.attr("style", "-ms-touch-action: manipulation; touch-action: manipulation;");
			
			$btn.click(function() { 
				return group.button_click(group, $btn, $input); 
			});
		});
	}
	ButtonGroup.prototype.button_click = function(group, $btn, $input) {
		var checked_status = !!$input.prop('checked'); // couple with its radio button
		if(group.kind === 'radio') {
			group.$buttons.removeClass('btn-checked'); // uncheck all
			checked_status = false; // can't turn off the radio
		}
		$btn.toggleClass('btn-checked', ! checked_status);
		if(group.kind === 'check') {
			$btn.find('i').toggleClass('fa-check', ! checked_status);
		}
		$input.prop('checked', ! checked_status); // check the real input
		$btn.change();
		return false;
	};

	function Survey() {
		this.$form = $("form");
		this.$progressbar = $('.progress .progress-bar');
		this.already_answered = this.$progressbar.data('already-answered');
		this.items_left = this.$progressbar.data('items-left');
		this.items_on_page = this.$progressbar.data('items-on-page');
		if(!$('.default_formr_button')[0]) this.items_on_page--; // we don't count submit buttons (but there is the special case of the default one)
//		console.log("this.items_on_page",this.items_on_page);
		this.hidden_but_rendered = this.$progressbar.data('hidden-but-rendered');
//		console.log("this.hidden_but_rendered",this.hidden_but_rendered);
		this.percentage_minimum = this.$progressbar.data('percentage-minimum');
		this.percentage_maximum = this.$progressbar.data('percentage-maximum');
		this.form_inputs = {};
	
		// initialising special items
		// --------------------------
		
		$("button.submit_automatically_after_timeout").each(function(i,elm) {
			var white_cover = $('<div class="white_cover"></div>');
			$('<div class="submit_fuse_box"><div class="submit_fuse"></div></div>').appendTo(elm);
			white_cover.appendTo("body");
			$(window).on("load", function() {
				var timeout = $(elm).data('timeout');
				white_cover.remove();
				window.setTimeout(function() {
					$(elm).click();
				}, timeout);
				$(".submit_fuse").animate({ "width": 0}, timeout);
			});
		});
		
		webshim.ready('DOM geolocation',function() {
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
		webshim.ready('DOM forms forms-ext dom-extend', function()
	    {
	        var mc_buttons = $('div.btn-radio, div.btn-checkbox, div.btn-check');
			mc_buttons.each(function(i, elm) {
				new ButtonGroup(elm);
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
					width: "width:300px",
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
			$(".clickable_map").each(function(i,elm)
			{
				"use strict";
				var $elm = $(elm);
				$elm.find("label").attr("for",null);
				var img = $elm.find("label img");
				var four_corners = $("<div class='map_link_container'><a class='topleft'></a><a class='topright'></a><a class='bottomleft'></a><a class='bottomright'></a></div>");
				four_corners.appendTo($elm.find("label"));
				img.appendTo(four_corners);
				$elm.find("label div a").click(function(e) {
					$elm.find('.selected').removeClass("selected");
					$elm.find("input[type=text]").val($(this).attr("class")).change();
					$(this).addClass("selected");
					return false;
				});
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
			    var slctdata0 = slct.attr('data-select2add');
			    if (typeof slctdata0 != 'object') {
			        slctdata0 = $.parseJSON(slctdata0);
			    }
			    var slctdata_arr;
			    var slctdata = [];
				for(var u = 0; u < slctdata0.length; u++) {
					slctdata_arr = slctdata0[u].id.split(",");
				    for(var j = 0; j < slctdata_arr.length; j++) {
						if(slctdata_arr[j].trim().length > 0) {
					       slctdata.push({ "id": slctdata_arr[j], "text" : slctdata_arr[j] });
					   }
				    }
				}
				
				var is_network_selector = $(elm).parents(".form-group").hasClass("network_select") || $(elm).parents(".form-group").hasClass("ratgeber_class") || $(elm).parents(".form-group").hasClass("cant_add_choice");
				
				slct.select2({
					createSearchChoice:function(term, data)
					{ 
						if(is_network_selector)
							return null; // don't allow choice creation
						
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
							data = new Array(intermed.length);
							for(var e = 0; e < intermed.length; e++) {
								data[e] = {id: intermed[e], text: intermed[e] };
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
				webshim.ready('forms forms-ext dom-extend form-validators', function() {
					webshim.addShadowDom(slct, slct.select2("container"));
				});
				
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
		$(".form-group:not([data-showif])").each(function(i,elm) // walk through all form elements that are automatically shown
		{
	        $(elm).find("input.item_shown").val(pageload_time);
	        $(elm).find("input.item_shown_relative").val(relative_time);
	    });
		
		$(".form-group").each(function(i, elm) { // initialise ever changed tracker
			$(elm).change(function(){
			   $(this).addClass('formr_answered');
               $(this).find("input.item_answered").val(mysql_datetime());
               $(this).find("input.item_answered_relative").val(window.performance.now ? performance.now() : null);
			});
		});
		$(".form-group.item-submit").each(function(i, elm) { // track submit buttons too
			$(elm).find("button").click(function(){
               $(elm).find("input.item_answered").val(mysql_datetime());
               $(elm).find("input.item_answered_relative").val(window.performance.now ? performance.now() : null);
			});
		});
	}
	Survey.prototype.update = function (e) {
		/// update at most every 500ms
		var now = new Date().getTime();
		if(this.last_update && this.last_update + 500 > now) {
			window.setTimeout($.proxy(this.update, this), this.last_update + 500 - now);
			return;
		} else {
			this.last_update = now;
		}
		
		this.getData();
		this.showIf();
		this.getProgress();
	};
	Survey.prototype.getData = function () {
		var badArray = this.$form.serializeArray(); // items that are valid for submission http://www.w3.org/TR/html401/interact/forms.html#h-17.13.2
		this.data = {};
		var survey = this;
		
		$.each(badArray, function(i, obj)
		{
			if(obj.name.indexOf('_') !== 0 && obj.name != "session_id") { // skip hidden items beginning with underscore (e.g _item_view)
				if(obj.name.indexOf('[]', obj.name.length - 2) > -1) obj.name = obj.name.substring(0,obj.name.length - 2);
		
				if(obj.value === "" && $("input[type=hidden][name='" + obj.name + "']").length === 1 && obj.value === $("input[type=hidden][name='" + obj.name + "']").attr("value")) {
					return true;
				}
		
				if(!survey.data[ obj.name ]) {
                    var val = obj.value;
                    if($.isNumeric(val)) {
                        val = parseFloat(val);
                    }
                    survey.data[obj.name] = val;
                }
				else {
                    survey.data[obj.name] += ", " + obj.value;
                }
			}
		});
	
	};
	Survey.prototype.getProgress = function () {
		var survey = this;
		survey.items_answered_on_page = $(".formr_answered").length+0;
		survey.items_visible_on_page = $(".form-group:not(.hidden)").length+0;

		var prog_here = (survey.items_answered_on_page + survey.already_answered) / ( survey.items_visible_on_page + survey.items_left + survey.already_answered);
	
		var prog = prog_here * (survey.percentage_maximum - survey.percentage_minimum);  // the fraction of this survey that was completed is multiplied with the stretch of percentage that it was accorded
		prog = prog + survey.percentage_minimum;

		if(prog > survey.percentage_maximum) prog = survey.percentage_maximum;
	
		survey.$progressbar.css('width',Math.round(prog)+'%');
		survey.$progressbar.text(Math.round(prog)+'%');
		return prog;
	};

	Survey.prototype.showIf = function(e)
	{
		var survey = this;
		if(! survey.items_with_showifs)
			survey.items_with_showifs = $(".form-group[data-showif]");
		var any_change = false;
		survey.items_with_showifs.each(function(i,elm) // walk through all form elements that are dynamically shown/hidden
		{
			var showif = $(elm).data('showif'); // get specific condition

			with(survey.data) // using the form data as the environment
			{
				var hide = true; // hiding is the default, if the try..catch fails
				try
				{
					hide = ! eval(showif); // evaluate the condition
				}
				catch(e)
				{
					if(window.console) console.log("JS showif failed",showif, e,  $(elm).find('input').attr('name'));
				}
		
				if($(elm).hasClass('hidden') != hide) {
					any_change = true;
					$(elm).toggleClass('hidden', hide); // show/hide depending on evaluation
					$(elm).find('input,select,textarea,button').prop('disabled', hide); // enable/disable depending on evaluation
					$(elm).find('.select2-container').select2('enable',! hide); // enable/disable select2 in firefox 10, doesn't work via shadowdom
	                if(! hide) {
	                    $(elm).find("input.item_shown").val(mysql_datetime());
	                    $(elm).find("input.item_shown_relative").val(window.performance.now ? performance.now() : null);
					}
				}
			}
		});
		return any_change;
	};
    Survey.prototype.doMonkey = function(monkey_iteration) {
		var survey = this;
        
        if(monkey_iteration > 2) return false;
        else if (monkey_iteration === undefined) monkey_iteration = 0;
        else monkey_iteration++;
        
		var items_left = $("form.main_formr_survey .form-row:not(.hidden):not(.formr_answered):not(.item-submit)");
        var date                     = new Date();
        var dateString               = date.toISOString().split('T')[0];
        var defaultByType            = {
            text                     : "thank the formr monkey",
            textarea                 : "thank the formr monkey\nmany times",
            year                     : date.getFullYear(),
            email                    : "formr_monkey@example.org",
            url                      : "http://formrmonkey.example.org/",
            date                     : "07-08-2015",
            month                    : "07-08-2015",
            yearmonth                : "07-08-2015",
            week                     : "07-08-2015",
            datetime                 : dateString,
            'datetime-local'         : date.toISOString(),
            day                      : date.getDay(),
            time                     : "11:22",
            color                    : "#ff0000",
            number                   : 20,
            tel                      : "1234567890",
            cc                       : "4999-2939-2939-3",
            range                    : 1
        };
        
        items_left.each(function(i, formRow)
        {
            // adapted from https://github.com/chrispederick/web-developer/
            formRow                      = $(formRow);
            var inputElement             = null;
            var inputElementMaxlength    = null;
            var inputElementName         = null;
            var inputElements            = null;
            var inputElementType         = "text";
            var option                   = null;
            var options                  = null;
            var selectElement            = null;
            var selectElements           = null;
            var textAreaElement          = null;
            var textAreaElements         = null;
            var textAreaElementMaxlength = null;
            var maximumValue             = 0;
            var minimumValue             = 0;

              select2Elements    = formRow.find(".select2-container:visible");
              // Loop through the select2 tags
              for(j = 0, m = select2Elements.length; j < m; j++)
              {
                select2Element = $(select2Elements[j]);

                // If the button element is not disabled and the value is not set
                if(select2Element.data('select2').opts.data) {
                    select2Element.select2('data', select2Element.data('select2').opts.data[0]);
                } else if (select2Element.data('select2').select) {
                    select2Element.select2('val', select2Element.data('select2').select[0].options[1].value);
                }
                return;
              }

              buttonElements    = formRow.find("button.btn:visible");
              
              // Loop through the button tags
              for(j = 0, m = buttonElements.length; j < m; j++)
              {
                buttonElement = buttonElements[j];

                // If the button element is not disabled and the value is not set
                if(!buttonElement.disabled)
                {
                    buttonElement.click();
                }
                return;
              }
              
              selectElements   = formRow.find("select:visible");
              // Loop through the select tags
              for(j = 0, m = selectElements.length; j < m; j++)
              {
                selectElement = selectElements[j];

                // If the select element is not disabled and the value is not set
                if(!selectElement.disabled && !selectElement.value.trim())
                {
                  options = selectElement.options;

                  // Loop through the options
                  for(var k = 0, n = options.length; k < n; k++)
                  {
                    option = options.item(k);

                    // If the option is set and the option text and option value are not empty
                    if(option && option.text.trim() && option.value.trim())
                    {
                      selectElement.selectedIndex = k;


                      break;
                    }
                  }
                }
                return;
              }
              
              inputElements    = formRow.find("input:not(.ws-inputreplace):not(input[type=hidden])");
              // Loop through the input tags
              for(var j = 0, m = inputElements.length; j < m; j++)
              {
                inputElement = inputElements[j];
                inputElementName      = inputElement.getAttribute("name");

                // If the input element is not disabled
                if(!inputElement.disabled)
                {
                  inputElementType = inputElement.getAttribute("type").toLowerCase();
                  // If the input element value is not set and the type is not set or is one of the supported types
                  if(defaultByType[inputElementType])
                  {
                    inputElementMaxlength = inputElement.getAttribute("maxlength");
                    
                    if(defaultByType[inputElementType]) {
                        $(inputElement).val(defaultByType[inputElementType]);
                    }
                    
                    if(inputElement.max) {
                        $(inputElement).val(inputElement.max + "");
                    }
                    if(inputElement.min) {
                        $(inputElement).val(inputElement.min + "");
                    }
                    // If the input element has a maxlength attribute
                    if(inputElementMaxlength && inputElement.value > inputElementMaxlength)
                    {
                      $(inputElement).val( inputElement.value.substr(0, inputElementMaxlength) );
                    }
                  }
                  else if((inputElementType == "checkbox" || inputElementType == "radio"))
                  {
                    $(inputElement).prop('checked',true);
                  }
                }
              }

              textAreaElements = formRow.find("textarea:visible");
              // Loop through the text area tags
              for(j = 0, m = textAreaElements.length; j < m; j++)
              {
                textAreaElement = textAreaElements[j];

                // If the text area element is not disabled and the value is not set
                if(!textAreaElement.disabled && !textAreaElement.value.trim())
                {
                  textAreaElementMaxlength = textAreaElement.getAttribute("maxlength");
                  $(textAreaElement).val(defaultByType.textarea);

                  // If the text area element has a maxlength attribute
                  if(textAreaElementMaxlength && textAreaElement.value > textAreaElementMaxlength)
                  {
                    textAreaElement.value = textAreaElement.value.substr(0, textAreaElementMaxlength);
                  }
                }
              }
              
        });
        // get progress
        items_left.each(function(i, elm)
        {
            $(elm).trigger('change');
        });
        survey.doMonkey(monkey_iteration);
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