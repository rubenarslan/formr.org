$(document).ready(function() {

    // initialising special items
    // --------------------------
    webshim.ready('geolocation',function() {
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
	// fixme: FOUCs for rating_buttons etc in IE8
    
    webshim.ready('forms-ext dom-extend',function() {
        $('.item-number.counter input').each(function() {
            var $input = $(this); 
            $input.hide();
            var btns = $('<div class="btn-group"><button class="btn btn-lg btn-down"><i class="fa fa-minus-circle"></i></button><button class="btn btn-lg btn-up"><i class="fa fa-plus-circle"></i></button></button></div>')
            btns.insertAfter($input);
            btns.find(".btn-down").click(function()
            {
                if( $input.attr('min') < $input.attr('value') ) 
                {
                    $input.attr('value', +$input.attr('value') - 1 );   
                    $input.change();
                }
                return false;
            });
            btns.find(".btn-up").click(function()
            {
                if( $input.attr('max') > $input.attr('value') ) 
                {
                    $input.attr('value', +$input.attr('value') + 1 );   
                    $input.change();
                }
                return false;
            });
            new FastClick(btns.find(".btn-down"));
            new FastClick(btns.find(".btn-up"));
           webshim.addShadowDom($input[0], btns[0]);
    	});
	
    	$('div.btn-radio button.btn').off('click').click(function(event){
    		var $btn = $(this);
    		$('#'+$btn.attr('data-for')).prop('checked',true); // couple with its radio button
    		var all_buttons = $btn.closest('div.btn-group').find('button.btn'); // find all buttons
    		all_buttons.removeClass('btn-checked'); // uncheck all
    		$btn.addClass('btn-checked'); // check this one
            $btn.change();
    		return false;
    	}).each(function() {
        	var $btn = $(this);
    		var is_checked_already = !!$('#'+$btn.attr('data-for')).prop('checked'); // couple with its radio button
    		$btn.toggleClass('btn-checked', is_checked_already);
        
    		$btn.closest('div.btn-group').removeClass('hidden'); // show special buttons
    		$btn.closest('.controls').find('label[class!=keep-label]').addClass('hidden'); // hide normal radio buttons
        
            new FastClick(this);
		
            webshim.addShadowDom($('#'+$btn.attr('data-for')), $btn.closest('div.btn-group'));
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

            new FastClick(this);

            webshim.addShadowDom($('#'+$btn.attr('data-for')), $btn.closest('div.btn-group'));
    	});
        
	
    	$('div.btn-check button.btn').off('click').click(function(event){
            var $btn = $(this);
            $('#'+$btn.attr('data-for')).trigger("togglecheck"); // toggle the button
    		return false;
    	}).each(function() {
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
        
            new FastClick(this);
        
            webshim.addShadowDom($('#'+$btn.attr('data-for')), $btn.closest('div.btn-group'));
    	});
	
    	$("select.select2zone, .form-group.select2 select").each(function(i,elm)
        {
            
    		var slct = $(elm); 
            slct.select2();
            webshim.addShadowDom(slct, slct.select2("container"));
        });
    	$(".select2pills select").each(function(i,elm)
    	{
    		var slct = $(elm); 
    		slct.select2({
                width: "element",
                dropdownCssClass: "bigdrop", // apply css that makes the dropdown taller
    			maximumSelectionSize: slct.data('select2maximumSelectionSize'),
    			maximumInputLength: slct.data('select2maximumInputLength'),
                formatResult: function(pill) {
                    if(pill.id != '')
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
    
    $('form').on('change', getProgress);
    getProgress();
    showIf();
});

function getProgress() {
    $progressbar = $('.progress .progress-bar');

//    var successful_controls = $('form').serializeArray(); 

    var badArray = $('form').serializeArray(); // items that are valid for submission http://www.w3.org/TR/html401/interact/forms.html#h-17.13.2
    var successful_controls = {};
    $.each(badArray, function(i, obj)
    {
        if(obj.name.indexOf('[]', obj.name.length - 2) > -1) obj.name = obj.name.substring(0,obj.name.length - 2);
        if(!successful_controls[ obj.name ]) successful_controls[obj.name] = obj.value;
        else successful_controls[obj.name] += ", " + obj.value;
    });
    
    var remaining_items = $progressbar.data('number-of-items');
    var remaining_percentage = 1 - $progressbar.data('starting-percentage')/100;
//    console.log(remaining_items, remaining_percentage);
	var items_answered_on_page = 0;
    var page_items = 0;
    
	$.each(successful_controls,function(name,value){

        var elm_non_hidden = $(document.getElementsByName(name).length ? document.getElementsByName(name) : document.getElementsByName(name+"[]")).filter(":not(input[type=hidden])");
        
        if(typeof change_events_set == 'undefined')
        {
            $(elm_non_hidden).parents(".form-group").change(function(){

               $(this).data('ever-changed', true);
              $(this).off('change'); 
            });
        }
        var elm_non_hidden = elm_non_hidden[0];

        if(elm_non_hidden)
        {
            page_items++;

            
    		if(value.length > 0) // if it's not empty, you get  //  || parseFloat(elm.value)
            {
                if($(elm_non_hidden).parents(".form-group").data('ever-changed')) //elm.value == elm_non_hidden.defaultValue) 
               {
           			items_answered_on_page += 0.5; // half a point for changing the default value
                    
                    if(elm_non_hidden.validity.valid) { // if it is valid like this, it gets half a point
            			items_answered_on_page += 0.5;
                    }
                }
                // cases: 
                // range, default: 0 + 0.5 = 0.05
                // email, "text": 0.5 + 0 = 0.5
                // text, "": 0 + 0
                // text, "xx": 0.5 + 0.5 = 1
            }
        }
	});

	var prog_on_remainder = items_answered_on_page / remaining_items;
	if(prog_on_remainder > 1) prog_on_remainder = 1;
    
    var prog = ((1 - remaining_percentage) + (remaining_percentage * prog_on_remainder)) * 100;
            
	$progressbar.css('width',Math.round(prog)+'%');
	$progressbar.text(Math.round(prog)+'%');
    change_events_set = true;
}


function showIf()
{
    $('form').change(function()
    {
        var badArray = $('form').serializeArray();
        var subdata = {};
        $.each(badArray, function(i, obj)
        {
            if(obj.name.indexOf('[]', obj.name.length - 2) > -1) obj.name = obj.name.substring(0,obj.name.length - 2);
            if(!subdata[ obj.name ]) subdata[obj.name] = obj.value;
            else subdata[obj.name] += ", " + obj.value;
        });
        $(".form-group[data-showif]").each(function(i,elm)
        {
            var showif = $(elm).data('showif');
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
            try
            {
                with(subdata)
                {
                    var hide = ! eval(showif);
                    $(elm).toggleClass('hidden', hide); // show/hide depending on evaluation
                    $(elm).find('input,select,textarea').prop('disabled', hide); // enable/disable depending on evaluation
                    $(elm).find('.select2-container').select2('enable',! hide); // enable/disable select2 in firefox 10, doesn't work via shadowdom
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

        })
    }).change();
}

function flatStringifyGeo(geo) {
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