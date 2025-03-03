import $ from 'jquery';
import webshim from 'webshim';
import { mysql_datetime, flatStringifyGeo  } from './main.js';
import '@khmyznikov/pwa-install';
import { ButtonGroup, initializeButtonGroups } from './components/ButtonGroup';
import { initializeAudioRecorders } from './components/AudioRecorder';
import { initializePWAInstaller, initializePushNotifications, initializeRequestPhone } from './components/PWAInstaller';
import { initializeSelect2Components } from './components/Select2Initializer';
import { FormMonkey } from './components/FormMonkey';

var is = {
    na: function(x) {
        return typeof x === "undefined";
    }
};

(function () {

    /* 
     add shadow dom to the button group
    */
    function ButtonGroup(item) {
        this.$item = $(item);
        this.$button_group = this.$item.find(".btn-group");
        if (this.$item.hasClass("btn-checkbox")) {
            this.kind = "checkbox";
        } else if (this.$item.hasClass("btn-check")) {
            this.kind = "check";
        } else {
            this.kind = "radio";
        }
        this.$buttons = this.$button_group.find(".btn");
        this.$inputs = this.$item.find("input[id]");
        var group = this;
        this.$buttons.off('click').each(function () {
            var $btn = $(this),
                    $input = group.$inputs.filter('#' + $btn.attr('data-for'));
            var is_checked_already = !!$input.prop('checked'); // couple with its radio button
            $btn.toggleClass('btn-checked', is_checked_already);
            webshim.ready("dom-extend", function () {
                webshim.addShadowDom($input, group.$button_group);
            });

            // hammer time
            $btn.attr("style", "-ms-touch-action: manipulation; touch-action: manipulation;");

            $btn.click(function () {
                return group.button_click(group, $btn, $input);
            });
        });
    }
    ButtonGroup.prototype.button_click = function (group, $btn, $input) {
        var checked_status = !!$input.prop('checked'); // couple with its radio button
        if (group.kind === 'radio') {
            group.$buttons.removeClass('btn-checked'); // uncheck all
            checked_status = false; // can't turn off the radio
        }
        $btn.toggleClass('btn-checked', !checked_status);
        if (group.kind === 'check') {
            $btn.find('i').toggleClass('fa-check', !checked_status);
        }
        $input.prop('checked', !checked_status); // check the real input
        if (group.kind === 'checkbox') { // messy fix to make webshims happy
            $input.triggerHandler('click.groupRequired');
        }
        $btn.change();
        return false;
    };

    function Survey() {
        this.$form = $("form");
        this.$progressbar = $('.progress .progress-bar');
        this.already_answered = this.$progressbar.data('already-answered');
        this.items_left = this.$progressbar.data('items-left');
        this.items_on_page = this.$progressbar.data('items-on-page');
        if (!$('.default_formr_button')[0])
            this.items_on_page--; // we don't count submit buttons (but there is the special case of the default one)
        this.hidden_but_rendered = this.$progressbar.data('hidden-but-rendered');
        this.percentage_minimum = this.$progressbar.data('percentage-minimum');
        this.percentage_maximum = this.$progressbar.data('percentage-maximum');
        this.form_inputs = {};
        this.last_update = false;
        this.next_update = false;
        this.dont_update = false;
        this.spinner = ' <i class="fa fa-spinner fa-spin"></i>';
        this.counterBtns = $('<div class="btn-group"><button class="btn btn-lg btn-down"><i class="fa fa-minus-circle"></i></button><button class="btn btn-lg btn-up"><i class="fa fa-plus-circle"></i></button></div>');
        this.initializeComponents();
    }

    Survey.prototype.initializeComponents = function() {
        // Initialize all components
        initializeButtonGroups();
        initializeAudioRecorders();
        initializePWAInstaller();
        initializePushNotifications();
        initializeRequestPhone();
        initializeSelect2Components();
        this.initializeCounters();
        this.initializeFormValidation();
        this.initializeFormSubmission();
        this.initializeItemTracking();
        this.initializeClickableMap();
        this.initializeAutoSubmit();
        this.initializeGeolocation();
    }

    Survey.prototype.initializeAutoSubmit = function() {
        $("button.submit_automatically_after_timeout").each(function (i, elm) {
            $('<div class="submit_fuse_box"><div class="submit_fuse"></div></div>').appendTo(elm);
            $(window).on("load", function () {
                var timeout = $(elm).data('timeout');
                if(timeout < 0) { // wait until you can submit
                    $(elm).prop('disabled', true);
                    $(elm).removeClass('btn-info');
                    window.setTimeout(function () {
                        $(elm).prop('disabled', false);
                        $(elm).addClass('btn-info');
                    }, -1 * timeout);
                    $(".submit_fuse").animate({"width": 0}, -1 * timeout);
                } else { // submit before it gets auto-submitted
                    window.setTimeout(function () {
                        $(elm).click();
                    }, timeout);
                    $(".submit_fuse").animate({"width": 0}, timeout);
                }
                $(".white_cover").remove();
            });
        });
    }

    Survey.prototype.initializeGeolocation = function() {
        webshim.ready('DOM geolocation', function () {
            $('.geolocator').click(function () {
                var real_loc = $(this).closest('.controls').find('input[type=hidden]');
                var enter_loc = $(this).closest('.controls').find('input[type=text]');

                enter_loc.attr('placeholder', 'You can also enter your location manually');
                enter_loc.prop('readonly', false);

                navigator.geolocation.getCurrentPosition(
                    function (pos) {
                        real_loc.val(flatStringifyGeo(pos));
                        enter_loc.val("lat:" + pos.coords.latitude + "/long:" + pos.coords.longitude);
                        enter_loc.prop('readonly', true);
                    },
                    function (err) {
                        // error handling - this isn't called in firefox, when the user clicks "Not now".
                    }
                );
                return false;
            }).each(function () {
                $(this).closest('.input-group-btn.hidden').removeClass('hidden');
            });
        });
    }

    Survey.prototype.initializeClickableMap = function() {
        $(".clickable_map").each(function (i, elm) {
            var $elm = $(elm);
            $elm.find("label").attr("for", null);
            var img = $elm.find("label img");
            var four_corners = $("<div class='map_link_container'><a class='topleft'></a><a class='topright'></a><a class='bottomleft'></a><a class='bottomright'></a></div>");
            four_corners.appendTo($elm.find("label"));
            img.appendTo(four_corners);
            $elm.find("label div a").click(function (e) {
                $elm.find('.selected').removeClass("selected");
                $elm.find("input[type=text]").val($(this).attr("class")).change();
                $(this).addClass("selected");
                return false;
            });
        });
    }

    Survey.prototype.initializeItemTracking = function() {
        var pageload_time = mysql_datetime();
        var relative_time = window.performance.now ? performance.now() : null;
        $(".form-group:not([data-showif])").each(function (i, elm) {
            $(elm).find("input.item_shown").val(pageload_time);
            $(elm).find("input.item_shown_relative").val(relative_time);
        });

        $(".form-group").each(function (i, elm) {
            $(elm).change(function () {
                $(this).addClass('formr_answered');
                $(this).find("input.item_answered").val(mysql_datetime());
                $(this).find("input.item_answered_relative").val(window.performance.now ? performance.now() : null);
            });
        });

        $(".form-group.item-submit").each(function (i, elm) {
            $(elm).find("button").click(function () {
                $(elm).find("input.item_answered").val(mysql_datetime());
                $(elm).find("input.item_answered_relative").val(window.performance.now ? performance.now() : null);
            });
        });
    }

    Survey.prototype.initializeFormSubmission = function() {
        var survey = this;
        $('form.main_formr_survey').bind('submit', function(e) {
            var $form = $(this);
            var $button = $form.find('.form-group.item-submit button');
            if ($button.find('.fa-spinner').length) {
                return false;
            }
            if ($form.checkValidity()) {
                $button.append(survey.spinner);
                $button.prop('disabled', true);
                return true;
            } else {
                return false;
            }
        });
    }

    Survey.prototype.initializeFormValidation = function() {
        webshim.ready('forms forms-ext dom-extend form-validators', function () {
            webshim.addCustomValidityRule('always_invalid', function (elem, val) {
                if (!$(elem).hasClass('always_invalid')) {
                    return;
                }
                return true;
            }, 'Cannot submit while there are problems with openCPU.');

            // Find all file inputs with the 'data-max-size' attribute
            $('input[type="file"][data-max-size]').each(function () {
                const $input = $(this);
                const maxSize = parseInt($input.data('max-size'), 10);

                // Attach a change event handler to the file input
                $input.on('change', function () {
                    let isValid = true;
                    
                    // Check each selected file's size
                    if (this.files) {
                        for (let i = 0; i < this.files.length; i++) {
                            if (this.files[i].size > maxSize) {
                                isValid = false;
                                break;
                            }
                        }
                    }

                    // Apply the validation using webshim
                    $input[0].setCustomValidity(
                        isValid ? '' : ('Selected file exceeds the maximum size limit of ' + maxSize/1024/1024 + 'MB'));
                    $input[0].reportValidity();
                });
            });

            webshim.refreshCustomValidityRules();
        });
    }

    Survey.prototype.initializeCounters = function() {
        var survey = this;
        webshim.ready('DOM forms forms-ext dom-extend', function () {
            $('.form-group.item-number.is-counter .controls input').each(function () {
                var $input = $(this);
                var $parent = $input.parents('span');
                var $btns = survey.counterBtns;

                $parent.hide();
                $btns.insertAfter($parent);
                toggleCounterElements($input.val());

                // bind-clicks
                $btns.find('.btn').click(function (e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var val = 1;
                    if ($input.val()) {
                        val = +$input.val();
                    }
 
                    if ($btn.is('.btn-down') && $input.attr('min') < val) {
                        val -= 1;
                    } else if ($btn.is('.btn-up') && $input.attr('max') > val) {
                        val += 1;
                    }

                    toggleCounterElements(val);
                    return false;
                });

                function toggleCounterElements(val) {
                    // get the counter name and show/hide corresponding elements
                    var classList = $input.parents('.is-counter').attr('class').replace(/\s+/g, ' ').split(' ');
                    var counterName = null;
                    for (var i in classList) {
                        if (classList[i].indexOf('-counter') !== -1 && classList[i] !== "is-counter") {
                            counterName = classList[i];
                            break;
                        }
                    }
                    // If there is no DOM element having the counter name value then return;
                    if (!$('.' + counterName + '-' + val).length) {
                        return false;
                    }
                    // Set value
                    $input.attr('value', val);
                    if (counterName) {
                        $('div[class*='+counterName+'-]').each(function() {
                            survey.setItemVisibility($(this), true);
                        });
                        for (var s = 1; s <= val; s++) {
                            survey.setItemVisibility($('.' + counterName + '-' + s), false);
                        }
                    }
                }
            });
        });
    }

    Survey.prototype.update = function () {
        var survey = this;
        if (survey.dont_update) {
            return;
        }
        /// update at most every 500ms
        var now = new Date().getTime();

        if (survey.last_update && survey.last_update + 500 > now) {
            if (!survey.next_update) { // don't queue up a bunch of updates
                survey.next_update = window.setTimeout($.proxy(survey.update, survey), survey.last_update + 500 - now);
            }
            return;
        } else {
            survey.getData();
            survey.showIf();
            var iterations = 0;
            var any_change = false;
            // as long as the data keeps changing, keep updating the showifs
            // this can happen because of showifs affecting each other for example
            // but do it no more than 10 times in a row (should not happen in well-designed surveys)
            while (iterations < 10 && survey.getData()) {
                any_change = survey.showIf();
                if (! any_change) { // once the showif conditions stop changing, we can stop the loop
                    break;
                } else {
                    iterations++;
                }
            }
            survey.getProgress();

            survey.last_update = now;
            survey.next_update = false;


            // if we ran into the limit of number of updates, schedule one in 500ms
            if(iterations >= 10) {
                survey.update();
            }
        }
    };

    Survey.prototype.getData = function () {
        var allItems = [];
        if (!this.$form[0]) {
            return;
        }

        $.each(this.$form[0].elements, function(){
            if( $.inArray( this.name, allItems ) < 0 ){
                allItems.push(this.name); 
            }
        });

        var badArray = this.$form.serializeArray(); // items that are valid for submission http://www.w3.org/TR/html401/interact/forms.html#h-17.13.2
        var survey = this;
        var old_data = survey.data;
        survey.data = {};

        $.each(allItems, function (i, obj) {
            if (obj.indexOf('_') !== 0 && obj != "session_id") { // skip hidden items beginning with underscore (e.g _item_view)
                if (obj.indexOf('[]', obj.length - 2) > -1) {
                    obj = obj.substring(0, obj.length - 2);
                }
                survey.data[obj] = undefined;
            }
        });

        $.each(badArray, function (i, obj) {
            if (obj.name.indexOf('_') !== 0 && obj.name != "session_id") { // skip hidden items beginning with underscore (e.g _item_view)
                if (obj.name.indexOf('[]', obj.name.length - 2) > -1) {
                    obj.name = obj.name.substring(0, obj.name.length - 2);
                }

                if (obj.value === "" && $("input[type=hidden][name='" + obj.name + "']").length === 1 && obj.value === $("input[type=hidden][name='" + obj.name + "']").attr("value")) {
                    survey.data[obj.name] = null;
                    return true;
                }

                if (!survey.data[obj.name]) {
                    var val = obj.value;
                    if ($.isNumeric(val)) {
                        val = parseFloat(val);
                    }
                    survey.data[obj.name] = val;
                } else {
                    survey.data[obj.name] += ", " + obj.value;
                }
            }
        });

        // any change?
        return JSON.stringify(old_data) !== JSON.stringify(survey.data);
    };

    Survey.prototype.getProgress = function () {
        var survey = this;
        if ($('.fmr-survey-page-count').length) {
            return survey.getPagingProgress();
        }
        survey.items_answered_on_page = $(".formr_answered").length + 0;
        survey.items_visible_on_page = $(".form-group:not(.hidden)").length + 0;

        var prog_here = (survey.items_answered_on_page + survey.already_answered) / (survey.items_visible_on_page + survey.items_left + survey.already_answered);

        var prog = prog_here * (survey.percentage_maximum - survey.percentage_minimum);  // the fraction of this survey that was completed is multiplied with the stretch of percentage that it was accorded
        prog = prog + survey.percentage_minimum;

        if (prog > survey.percentage_maximum)
            prog = survey.percentage_maximum;

        survey.$progressbar.css('width', Math.round(prog) + '%');
        survey.$progressbar.text(Math.round(prog) + '%');
        return prog;
    };

    Survey.prototype.getPagingProgress = function () {
        var survey = this;
        var prog = 0;
        var data = $('.fmr-survey-page-count').data();
        if (data.answereditems) {
            // revisiting page
            prog = data.progress;
        } else {
            var visibleItems =  $('.form-group:not(.hidden)').length + 0;
            var toAnswerItems = $('.form-group.required:not(.hidden,.item-submit,.formr_answered,.counter)').length + 0;
            var pageProg = ((visibleItems - toAnswerItems) / visibleItems);
            prog = (pageProg * data.pageprogress) + data.prevprogress;
        }

        var percentage = Math.round(prog * 100) + '%';
        survey.$progressbar.css('width', percentage);
        survey.$progressbar.text(percentage);
        return prog;
    };

    Survey.prototype.showIf = function (e) {
        var _survey = this;
        if (!_survey.items_with_showifs) {
            _survey.items_with_showifs = $(".form-group[data-showif]");
        }
        var any_change = false;
        _survey.items_with_showifs.each(function (i, elm) { // walk through all form elements that are dynamically shown/hidden
            var $elm = $(elm);
            var _showif = $elm.data('showif'); // get specific condition
            /* eslint-disable */
            const context = { ..._survey.data };
            var _hide = true;
            try {
                // Use new Function() to create a function in the context of the data
                const context = { ..._survey.data }; // Spread _survey.data into the context object
                // strip comments
                _showif = _showif.replace(/\/\*[\s\S]*?\*\/|\/\/.*/g, '').trim();

                // Create a function dynamically, passing the context as a parameter
                const fn = new Function('context', `
                    with (context) {
                        return !(${_showif});
                    }
                `);
            
                _hide = fn(context); // Call the function with context
            } catch (e) {
                if (window.console) {
                    console.log("JS showif failed", _showif, e, $elm.find('input').attr('name'));
                }
                // Fallback logic
                if ($elm.data('show')) {
                    _hide = false;
                }
            }

            any_change = _survey.setItemVisibility($elm, _hide);
            /* eslint-enable */
        });
        return any_change;
    };

    Survey.prototype.setItemVisibility = function($elm, hide) {
        if ($elm.hasClass('hidden') != hide) {
            $elm.toggle(!hide);
            $elm.toggleClass('hidden', hide); // show/hide depending on evaluation
            $elm.find('input,select,textarea,button').prop('disabled', hide); // enable/disable depending on evaluation
            $elm.find('.select2-container').select2('enable', !hide); // enable/disable select2 in firefox 10, doesn't work via shadowdom
            if (!hide) {
                $elm.find("input.item_shown").val(mysql_datetime());
                $elm.find("input.item_shown_relative").val(window.performance.now ? performance.now() : null);
            }
            return true;
        }
        return false;
    };

    Survey.prototype.doMonkey = function (monkey_iteration) {
        var survey = this;
        if (monkey_iteration > 2) {
            window.setTimeout(function () {
                $("form.main_formr_survey button[type=submit]").click();
            }, 700);
            return false;
        }
        else if (monkey_iteration === undefined)
            monkey_iteration = 0;
        else
            monkey_iteration++;

        survey.dont_update = true;

        var items_left = $("form.main_formr_survey .form-row:not(.hidden):not(.formr_answered):not(.item-submit)");
        var date = new Date();
        var dateString = date.toISOString().split('T')[0];
        var defaultByType = {
            text: "thank the formr monkey",
            textarea: "thank the formr monkey\nmany times",
            year: date.getFullYear(),
            email: "formr_monkey@example.org",
            url: "http://formrmonkey.example.org/",
            date: "07-08-2015",
            month: "07-08-2015",
            yearmonth: "07-08-2015",
            week: "07-08-2015",
            datetime: dateString,
            'datetime-local': date.toISOString(),
            day: date.getDay(),
            time: "11:22",
            color: "#ff0000",
            number: 20,
            tel: "1234567890",
            cc: "4999-2939-2939-3",
            range: 1
        };

        items_left.each(function (i, formRow) {
            // adapted from https://github.com/chrispederick/web-developer/
            formRow = $(formRow);
            var inputElement = null;
            var inputElementMaxlength = null;
            var inputElementName = null;
            var inputElements = null;
            var inputElementType = "text";
            var option = null;
            var options = null;
            var selectElement = null;
            var selectElements = null;
            var textAreaElement = null;
            var textAreaElements = null;
            var textAreaElementMaxlength = null;
            var maximumValue = 0;
            var minimumValue = 0;

            var select2Elements = formRow.find(".select2-container:visible");
            // Loop through the select2 tags
            for (j = 0, m = select2Elements.length; j < m; j++) {
                var select2Element = $(select2Elements[j]);

                // If the button element is not disabled and the value is not set
                if (select2Element.data('select2').opts.data) {
                    select2Element.select2('data', select2Element.data('select2').opts.data[0]);
                } else if (select2Element.data('select2').select) {
                    select2Element.select2('val', select2Element.data('select2').select[0].options[1].value);
                }
                return;
            }

            var buttonElements = formRow.find("button.btn:visible");
            // Loop through the button tags
            for (j = 0, m = buttonElements.length; j < m; j++) {
                var buttonElement = buttonElements[j];

                // If the button element is not disabled and the value is not set
                if (!buttonElement.disabled) {
                    buttonElement.click();
                }
                return;
            }

            selectElements = formRow.find("select:visible");
            // Loop through the select tags
            for (j = 0, m = selectElements.length; j < m; j++) {
                selectElement = selectElements[j];

                // If the select element is not disabled and the value is not set
                if (!selectElement.disabled && !selectElement.value.trim()) {
                    options = selectElement.options;

                    // Loop through the options
                    for (var k = 0, n = options.length; k < n; k++) {
                        option = options.item(k);

                        // If the option is set and the option text and option value are not empty
                        if (option && option.text.trim() && option.value.trim()) {
                            selectElement.selectedIndex = k;
                            break;
                        }
                    }
                }
                return;
            }

            inputElements = formRow.find("input:not(.ws-inputreplace):not(input[type=hidden])");
            // Loop through the input tags
            for (var j = 0, m = inputElements.length; j < m; j++) {
                inputElement = inputElements[j];
                inputElementName = inputElement.getAttribute("name");

                // If the input element is not disabled
                if (!inputElement.disabled) {
                    inputElementType = inputElement.getAttribute("type").toLowerCase();
                    // If the input element value is not set and the type is not set or is one of the supported types
                    if (defaultByType[inputElementType]) {
                        inputElementMaxlength = inputElement.getAttribute("maxlength");

                        if (defaultByType[inputElementType]) {
                            $(inputElement).val(defaultByType[inputElementType]);
                        }

                        if (inputElement.max) {
                            $(inputElement).val(inputElement.max + "");
                        }
                        if (inputElement.min) {
                            $(inputElement).val(inputElement.min + "");
                        }
                        // If the input element has a maxlength attribute
                        if (inputElementMaxlength && inputElement.value > inputElementMaxlength)
                        {
                            $(inputElement).val(inputElement.value.substr(0, inputElementMaxlength));
                        }
                    } else if ((inputElementType == "checkbox" || inputElementType == "radio")) {
                        $(inputElement).prop('checked', true);
                    }
                }
            }

            textAreaElements = formRow.find("textarea:visible");
            // Loop through the text area tags
            for (j = 0, m = textAreaElements.length; j < m; j++) {
                textAreaElement = textAreaElements[j];

                // If the text area element is not disabled and the value is not set
                if (!textAreaElement.disabled && !textAreaElement.value.trim()) {
                    textAreaElementMaxlength = textAreaElement.getAttribute("maxlength");
                    $(textAreaElement).val(defaultByType.textarea);

                    // If the text area element has a maxlength attribute
                    if (textAreaElementMaxlength && textAreaElement.value > textAreaElementMaxlength) {
                        textAreaElement.value = textAreaElement.value.substr(0, textAreaElementMaxlength);
                    }
                }
            }

        });
        // get progress
        items_left.each(function (i, elm) {
            $(elm).trigger('change');
        });
        survey.dont_update = false;
        survey.update();
        survey.doMonkey(monkey_iteration);
    };

    Survey.prototype.setUpCounters = function() {
        var survey = this;
        webshim.ready('DOM forms forms-ext dom-extend', function () {
            $('.form-group.item-number.is-counter .controls input').each(function () {
                var $input = $(this);
                var $parent = $input.parents('span');
                var $btns = survey.counterBtns;

                $parent.hide();
                $btns.insertAfter($parent);
                toggleCounterElements($input.val());

                // bind-clicks
                $btns.find('.btn').click(function (e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var val = 1;
                    if ($input.val()) {
                        val = +$input.val();
                    }
 
                    if ($btn.is('.btn-down') && $input.attr('min') < val) {
                        val -= 1;
                    } else if ($btn.is('.btn-up') && $input.attr('max') > val) {
                        val += 1;
                    }

                    toggleCounterElements(val);
                    return false;
                });

                function toggleCounterElements(val) {
                    // get the counter name and show/hide corresponding elements
                    var classList = $input.parents('.is-counter').attr('class').replace(/\s+/g, ' ').split(' ');
                    var counterName = null;
                    for (var i in classList) {
                        if (classList[i].indexOf('-counter') !== -1 && classList[i] !== "is-counter") {
                            counterName = classList[i];
                            break;
                        }
                    }
                    // If there is no DOM element having the counter name value then return;
                    if (!$('.' + counterName + '-' + val).length) {
                        return false;
                    }
                    // Set value
                    $input.attr('value', val);
                    if (counterName) {
                        $('div[class*='+counterName+'-]').each(function() {
                            survey.setItemVisibility($(this), true);
                        });
                        for (var s = 1; s <= val; s++) {
                            survey.setItemVisibility($('.' + counterName + '-' + s), false);
                        }
                    }
                }
            });
        });
    };

    $(function () { // on domready
        var survey = new Survey();
        survey.update();
        $('form.main_formr_survey').on('change', function () {
            survey.update();
        });

        if ($(".form-row.hidden").length > 0) {
            $(".show_hidden_items").click(function () {
                $('.form-row.hidden').removeClass("hidden");
                return false;
            });
            $(".show_hidden_items").attr('disabled', false);
        }
        if ($("button.monkey").length > 0) {
            var formMonkey = new FormMonkey(survey);
            $("button.monkey").click(function () {
                formMonkey.doMonkey(0);
                return false;
            });
            $("button.monkey").attr('disabled', false);
        }
    });

}());