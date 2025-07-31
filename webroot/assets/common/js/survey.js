import './pwa-register.js';
import $ from 'jquery';
import webshim from 'webshim';
import { mysql_datetime, flatStringifyGeo, bootstrap_modal, ajaxErrorHandling } from './main.js';
import { ButtonGroup, initializeButtonGroups } from './components/ButtonGroup';
import { initializeAudioRecorders } from './components/AudioRecorder';
import { initializePWAInstaller, initializePushNotifications, initializeRequestPhone, initializeRequestCookie } from './components/PWAInstaller';
import { initializeExpiryNotifier } from './components/ExpiryNotifier';
import { initializeSelect2Components } from './components/Select2Initializer';
import { FormMonkey } from './components/FormMonkey';

var is = {
    na: function(x) {
        return typeof x === "undefined";
    }
};

function ajaxifyLink(i, elm) {
    $(elm).click(function (e) {
        e.preventDefault();
        var $this = $(this);
        var old_href = $this.attr('href');
        console.log('ajaxifyLink clicked:', $this, 'href:', old_href);
        
        if (old_href === '' || old_href === 'javascript:void(0);') {
            console.log('Skipping invalid href:', old_href);
            return false;
        }
        
        $this.attr('href', '');
        console.log('Making AJAX request to:', old_href);

        $.ajax({
            type: "GET",
            url: old_href,
            dataType: 'html'
        }).done($.proxy(function (data) {
            console.log('AJAX request successful:', data);
            var $this = $(this);
            $this.attr('href', old_href);
            if (!$this.hasClass("danger")) {
                $this.css('color', 'green');
            }

            var $logo = $this.find('i.fa');
            if ($logo.hasClass("fa-stethoscope")) {
                $logo.addClass('fa-heartbeat');
                $logo.removeClass('fa-stethoscope');
            } else if ($logo.hasClass("fa-heartbeat")) {
                $logo.removeClass('fa-heartbeat');
                $logo.addClass('fa-stethoscope');
            } else {
                bootstrap_modal('Alert', data, 'tpl-feedback-modal');
            }

            if ($this.hasClass('refresh_on_success')) {
                console.log('Refreshing page due to refresh_on_success class');
                document.location.reload(true);
            }
        }, this)).fail($.proxy(function (e, x, settings, exception) {
            console.log('AJAX request failed:', e, x, settings, exception);
            $(this).attr('href', old_href);
            ajaxErrorHandling(e, x, settings, exception);
        }, this));
        return false;
    });
}

function ajaxifyForm(i, elm) {
    $(elm).submit(function (e) {
        e.preventDefault();
        var $this = $(this);
        var $submit = $this.find('button[type=submit].btn');
        $submit.attr('disabled', true);

        $.ajax({
            type: $this.attr('method'),
            url: $this.attr('action'),
            data: $this.serialize(),
            dataType: 'html',
        }).done($.proxy(function (data) {
            $submit.attr('disabled', false);
            $submit.css('color', 'green');
            $('.alerts-container').prepend(data);

            if ($submit.hasClass('refresh_on_success')) {
                document.location.reload(true);
            }
        }, this)).fail($.proxy(function (e, x, settings, exception) {
            $submit.attr('disabled', false);
            ajaxErrorHandling(e, x, settings, exception);
        }, this));
        return false;
    });
}

(function () {



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
        initializeRequestPhone();
        initializeRequestCookie();
        initializePWAInstaller();
        initializePushNotifications();
        initializeExpiryNotifier();
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
        $('form.main_formr_survey').on('submit', function(e) {
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

        var badArray = this.$form.serializeArray(); // items that are valid for submission https://www.w3.org/TR/html401/interact/forms.html#h-17.13.2
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

    $(function () { // on domready
        var survey = new Survey();
        survey.update();
        $('form.main_formr_survey').on('change', function () {
            survey.update();
        });

        if ($(".form-row.hidden").length > 0) {
            $(".show_hidden_items").on('click', function () {
                $('.form-row.hidden').removeClass("hidden");
                return false;
            });
            $(".show_hidden_items").attr('disabled', false);
        }

        if ($(".hidden_debug_message").length > 0) {
            $(".show_hidden_debugging_messages").on('click', function () {
                $('.hidden_debug_message').toggleClass("hidden");
                return false;
            });
            $(".show_hidden_debugging_messages").attr('disabled', false);
        }

        // Initialize AJAX functionality for monkey bar
        console.log('Initializing AJAX functionality...');
        var $formAjax = $('.form-ajax');
        var $linkAjax = $('.link-ajax');
        var $removalModal = $('.removal_modal');
        
        console.log('Found form-ajax elements:', $formAjax.length, $formAjax);
        console.log('Found link-ajax elements:', $linkAjax.length, $linkAjax);
        console.log('Found removal_modal elements:', $removalModal.length, $removalModal);
        
        $('.form-ajax').each(ajaxifyForm);
        $('.link-ajax').each(ajaxifyLink);

        $('.link-ajax .fa-pause').parent(".btn").on('mouseenter', function () {
            $(this).find('.fa').removeClass('fa-pause').addClass('fa-play');
        }).on('mouseleave', function () {
            $(this).find('.fa').addClass('fa-pause').removeClass('fa-play');
        });
        $('.link-ajax .fa-stop').parent(".btn").on('mouseenter', function () {
            $(this).find('.fa').removeClass('fa-stop').addClass('fa-play');
        }).on('mouseleave', function () {
            $(this).find('.fa').addClass('fa-stop').removeClass('fa-play');
        });

        // Handle monkey bar modals with data-href attributes
        $('.removal_modal').on('show.bs.modal', function (e) {
            console.log('Modal showing:', e);
            var $current_target = $(e.relatedTarget);
            var $modal = $(this);
            console.log('Modal trigger:', $current_target, 'data-href:', $current_target.data('href'));
            
            // Only apply table row styling if we're actually in a table context
            var $parent_row = $current_target.parents("tr");
            if ($parent_row.length) {
                $parent_row.css("background-color", "#ee5f5b");
            }
            
            $(this).find('.danger').attr('href', $current_target.data('href'));
            console.log('Setting danger button href to:', $current_target.data('href'));
            ajaxifyLink(1, $(this).find('.danger'));
            $(this).find('.danger').click(function (e) {
                console.log('Danger button clicked');
                $current_target.css("color", "#ee5f5b");
                if ($modal.hasClass('refresh_on_success')) {
                    window.setTimeout(function () {
                        document.location.reload(true);
                    }, 200);
                }
                $modal.modal("hide");
            });
        }).on("hide.bs.modal", function (e) {
            var $current_target = $(e.relatedTarget);
            var $parent_row = $current_target.parents("tr");
            if ($parent_row.length) {
                $parent_row.css("background-color", "transparent");
            }
        });

        if ($("button.monkey").length > 0) {
            var formMonkey = new FormMonkey(survey);
            $("button.monkey").on('click', function () {
                formMonkey.doMonkey(0);
                return false;
            });
            $("button.monkey").attr('disabled', false);
        }
    });

}());