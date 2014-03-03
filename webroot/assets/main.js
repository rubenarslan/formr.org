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
	if($('input[type=range]').css('display')!='none')
		$('input[type=range]').css('width',0);
    $('.range_ticks_output').each(function () {
		var thumb = $('.ws-range-thumb', this);
        var output = $('output', this);
		
        var changeSlider = function () {
            output.text($(this).prop('value') || '');
			output.css('left', thumb.css('left'));
        };

        $('.ws-range-rail', this).append(output);
		output.addClass('ws-range-tick-output');

        $('input[type="range"]', this)
            .on('input', changeSlider)
            .each(changeSlider);
    });
});
$.webshims.ready('form-validators', function(){
	//$.webshims.addCustomValidityRule(name of constraint, test-function, default error message); 
	var groupTimer = {};
	
	$.webshims.addCustomValidityRule('choose2days', function(elem, val){
		var name = elem.name;
		if(!name || elem.type !== 'checkbox' || !$(elem).hasClass('choose2days')){return;}
		var checkboxes = $( (elem.form && elem.form[name]) || document.getElementsByName(name));
		var isValid = checkboxes.filter(':checked:enabled');
		if(groupTimer[name]){
			clearTimeout(groupTimer[name]);
		}
		groupTimer[name] = setTimeout(function(){
			checkboxes
				.addClass('choose2days')
				.unbind('click.choose2days')
				.bind('click.choose2days', function(){
					checkboxes.filter('.choose2days').each(function(){
						$.webshims.refreshCustomValidityRules(this);
					});
				})
			;
		}, 9);
		
		if(isValid.length !== 2)
		{
			return true;
		} else
		{
			// [1,2] F
			// [1,7] F
			// [3,2] F
			// [1,3] T
			// [1,6] T
			var chosen = isValid.map( function() {
				return +$(this).val();
			}).get();
			
			
			var forbidden_wrong = $([chosen[0] - 2, chosen[0] - 1, chosen[0], chosen[0] + 1, chosen[0] + 2]);
			var forbidden = forbidden_wrong.map( function() {
				if(this < 1) return 7 + this;
				if(this > 7) return this - 7;
				return +this;
			});
			if($.inArray(chosen[1],forbidden)!==-1)
			{
				return true;
			}
			else
			{
				return false;
			}
		}
	}, 'Du musst zwei Wochentage auswählen, die mehr als zwei Tage auseinander liegen.');
	
	
	//changing default message
	$.webshims.customErrorMessages.choose2days[''] = 'Du musst zwei Wochentage auswählen, die mehr als zwei Tage auseinander liegen.';
	//adding new languages
	$.webshims.customErrorMessages.choose2days['de'] = 'Du musst zwei Wochentage auswählen, die mehr als zwei Tage auseinander liegen.';
});
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
	$('.range_ticks_output').each(function () {
		var output = $('output', this);
//		console.log(output);	
		var change = function () {
			output.text($(this).prop('value') || '');
		};
		$('input[type="range"]', this)
			.on('input', change)
			.each(change);
	});
	// fixme: FOUCs for rating_buttons etc in IE8
    
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
        
		
        $.webshims.addShadowDom($('#'+$btn.attr('data-for')), $btn.closest('div.btn-group'));
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

        $.webshims.addShadowDom($('#'+$btn.attr('data-for')), $btn.closest('div.btn-group'));
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
        
        $.webshims.addShadowDom($('#'+$btn.attr('data-for')), $btn.closest('div.btn-group'));
	});
	
	
	var pathArray = location.href.split( '/' );
	var protocol = pathArray[0];
	var host = pathArray[2];
	if(host==='localhost:8888') host = host + "/zwang";
	var url = protocol + '//' + host + "/";
	
	$("select.select2zone").each(function(i,elm)
    {
		var slct = $(elm); 
        slct.select2();
        $.webshims.addShadowDom(slct, slct.select2("container"));
    });
	$("input.select2add").each(function(i,elm)
	{
		var slct = $(elm); 
		var slctdata = $.parseJSON(slct.attr('data-select2add'));
		slct.select2({
			createSearchChoice:function(term, data)
			{ 
				if ($(data).filter(function() { 
					return this.text.localeCompare(term)==0; 
				}).length===0) 
				{
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
			maximumSelectionSize: slct.attr('data-select2maximumSelectionSize'),
			maximumInputLength: slct.attr('data-select2maximumInputLength'),
			data: slctdata, 
			multiple: !!slct.prop('multiple'), 
			allowClear: true,
            escapeMarkup: function (m) { return m; }
		});
        $.webshims.addShadowDom(slct, slct.select2("container"));
        
	});
	$(".select2pills select").each(function(i,elm)
	{
		var slct = $(elm); 
		slct.select2({
            width:400,
            dropdownCssClass: "bigdrop", // apply css that makes the dropdown taller
			maximumSelectionSize: slct.attr('data-select2maximumSelectionSize'),
			maximumInputLength: slct.attr('data-select2maximumInputLength'),
            formatResult: function(pill) {
                var markup = "<table class='movie-result'><tr>";
                markup += "<td class='movie-image' width='200'><img width='200px' alt='"+pill.text+"' src='/assets/img/pills/" + pill.id + ".jpg'/></td>";
                    markup += "<td class='movie-info'><div class='movie-title'>" + pill.text + "</div>";
                markup += "</td></tr></table>"
                return markup;
            },
            formatSelection: function (pill) {
                return pill.text;
            },
            escapeMarkup: function (m) { return m; }
		});
        $.webshims.addShadowDom(slct, slct.select2("container"));
	});
	
	$('*[title]').tooltip({
		container: 'body'
	});
	$('abbr.abbreviated_session').click(function()
    {
        $(this).text($(this).data("full-session"));
        $(this).off('click');
    });
    hljs.initHighlighting();
    
    
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
//                console.log(12);
               $(this).data('ever-changed', true);
              $(this).off('change'); 
            });
        }
        var elm_non_hidden = elm_non_hidden[0];
//        $(elm_non_hidden).parents(".controls").append($('<i class="fa fa-cloud"></i>'));

        if(elm_non_hidden)
        {
            page_items++;
/*            if(!$(elm_non_hidden).data('ever-changed'))
            {
                console.log(elm_non_hidden);
                $(elm_non_hidden).data('ever-changed', false); 
            }
            
*/    		if(value.length > 0) // if it's not empty, you get  //  || parseFloat(elm.value)
            {
//                $(elm_non_hidden).parents(".controls").append($('<i class="fa fa-cloud"></i>'));
                
//                console.log(elm.value);
                if($(elm_non_hidden).parents(".form-group").data('ever-changed')) //elm.value == elm_non_hidden.defaultValue) 
               {
           			items_answered_on_page += 0.5; // half a point for changing the default value
//                    $(elm_non_hidden).parents(".controls").append($('<i class="fa fa-circle"></i>'));
                    
                    if(elm_non_hidden.validity.valid) { // if it is valid like this, it gets half a point
            			items_answered_on_page += 0.5;
//                        $(elm_non_hidden).parents(".controls").append($('<i class="fa fa-check"></i>'));
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
//    console.log(items_answered_on_page)

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
//            if(+obj.value == obj.value) obj.value = +obj.value; // cast as numeric
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
/*            var parts = showif.match(/(\w+)(==|!=|>=|<=|<|>|%contains%)(\d+|'\w+')/)
            if(parts) // this is one of the simple showifs that we can read with JS
            {
                var item = parts[0];
                var comparator = parts[1];
                var compare_to = parts[2];
                if(subdata[item]) // does this item exist
                {
                    subdata[item] 
                }
            }
            */
        })
    }).change();
}

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