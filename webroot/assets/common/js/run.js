/*jshint scripturl:true*/
(function ($) {
    "use strict";
    function RunUnit(run) {
        this.run = run;
        this.block = $('<div class="run_unit"></div>');
        run.form.find('.run_units').append(this.block);
    }
    RunUnit.prototype.init = function (content) {
        this.block.htmlPolyfill($($.parseHTML(content))); // .html annoying but necessary, somewhere in here a clone where there should be none, appears
        this.position = this.block.find('.run_unit_position input.position');

        this.position_changed = false;
        this.position.change($.proxy(this.position_changes, this));

        this.dialog_inputs = this.block.find('div.run_unit_dialog input,div.run_unit_dialog select, div.run_unit_dialog button, div.run_unit_dialog textarea');
        this.description = this.block.find('.run_unit_description');
        this.unit_id = this.dialog_inputs.filter('input[name=unit_id]').val();
        this.run_unit_id = this.dialog_inputs.filter('input[name=run_unit_id]').val();
        this.special = this.dialog_inputs.filter('input[name=special]').val();
        this.block.attr('id', "run_unit_" + this.run_unit_id);
        this.dialog_inputs.on('input change', $.proxy(this.changes, this));
        this.description.on('input change', $.proxy(this.changes, this));
        this.save_inputs = this.dialog_inputs.add(this.position).add(this.description);

        // todo: file bug report with webshims, oninput fires only onchange for number inputs

        this.block.find('.hastooltip').tooltip({
            container: 'body'
        });
        this.block.find('.select2').select2();

        var slct = this.block.find('input.select2recipient');
        if (slct.length > 0) {
            if (!slct.select2("container").hasClass("select2-container")) { // if it's not already select2ified
                var slctdata0 = slct.attr('data-select2init'), slctdata;
                if (typeof slctdata0 != 'object') {
                    slctdata = $.parseJSON(slctdata0);
                } else {
                    slctdata = slctdata0;
                }
                slct.select2({
                    createSearchChoice: function (term, data)
                    {
                        if ($(data).filter(function () {
                            return this.text.localeCompare(term) === 0;
                        }).length === 0)
                        {
                            return {id: term, text: term};
                        }
                    },
                    initSelection: function (element, callback)
                    {
                        var data;
                        data = {id: element.val(), text: element.val()};
                        $.each(slctdata, function (k, v) {
                            if (v.id === element.val()) {
                                data = v;
                                return false;
                            }
                        });
                        callback(data);
                    },
                    data: slctdata
                });
            }
        }

        this.unsavedChanges = false;
        this.save_button = this.block.find('a.unit_save');

        this.block.find('button.from_days')
                .click(function (e)
                {
                    e.preventDefault();
                    var numberinput = $(this).closest('.input-group').find('input[type=number]');
                    var days = numberinput.val();
                    numberinput.val(days * 60 * 24).change();
                });

        this.test_button = this.block.find('a.unit_test');
        this.test_button.click($.proxy(this.test, this));

        this.remove_button = this.block.find('button.remove_unit_from_run');
        this.remove_button
                .click($.proxy(this.removeFromRun, this))
                .mouseenter(function () {
                    $(this).addClass('btn-danger');
                }).
                mouseleave(function () {
                    $(this).removeClass('btn-danger');
                });

        var textareas = this.block.find('textarea');
        if (textareas[0])
        {
            this.textarea = $(textareas[0]);
            this.session = this.hookAceToTextarea(this.textarea);
        }
        if (textareas[1])
        {
            this.textarea2 = $(textareas[1]);
            this.session2 = this.hookAceToTextarea(this.textarea2);
        }

        this.run.lock(this.run.lock_toggle.hasClass("btn-checked"), this.block);
        this.save_button.attr('disabled', true).removeClass('btn-info').text('Saved').click($.proxy(this.save, this));
                
    };

    RunUnit.prototype.position_changes = function (e) {
        if (!this.position_changed)
        {
            this.position_changed = true;
            this.run.reorder_button.addClass('btn-info').removeAttr('disabled');
        }
        this.position.parent().addClass('pos_changed');
    };

    RunUnit.prototype.changes = function (e) {
        if (!this.unsavedChanges) // dont touch the DOM for every change
        {
            this.unsavedChanges = true;
            this.save_button.addClass('btn-info').removeAttr('disabled').text('Save changes');
            this.test_button.attr('disabled', 'disabled');
        }
    };

    RunUnit.prototype.test = function (e) {
        e.preventDefault();
        var old_text = this.test_button.text();
        this.test_button.attr('disabled', true).html(old_text + bootstrap_spinner());

        var $unit = this.block;
        $.ajax({
            url: this.run.url + "/" + this.test_button.attr('href'),
            dataType: 'html',
            data: {"run_unit_id": this.run_unit_id, "special": this.special},
            method: 'GET'
        }).done($.proxy(function (data) {
            var $modal = bootstrap_modal('Test Results', data);
            $(".opencpu_accordion", $modal).collapse({toggle: true});

            this.test_button.html(old_text).removeAttr('disabled');
            var codeblocks = $modal.find('pre code');
            Array.prototype.forEach.call(codeblocks, hljs.highlightBlock);
            //	  $modal.find('#opencpu_accordion').on('hidden', function (event) {
            //		  event.stopPropagation()
            //	  });
            if ($modal.find(".download_r_code").length > 0) {
                $modal.find(".download_r_code").click(function () {
                    return download_next_textarea(this);
                });
            }

        }, this)).fail($.proxy(function (e, x, settings, exception) {
            this.test_button.attr('disabled', false).html(old_text);
            ajaxErrorHandling(e, x, settings, exception);
        }, this));

        return false;
    };

    RunUnit.prototype.save = function (e) {
        e.preventDefault();

        var old_text = this.save_button.text();
        this.save_button.attr('disabled', "disabled").html(old_text + bootstrap_spinner());

        if (this.session)
            this.textarea.val(this.session.getValue());
        if (this.session2)
            this.textarea2.val(this.session2.getValue());

        var $unit = this.block;
        $.ajax(
                {
                    url: this.run.url + "/" + this.save_button.attr('href'),
                    dataType: 'html',
                    data: this.save_inputs.serialize(),
                    method: 'POST',
                })
                .done($.proxy(function (data)
                {
                    if (data !== '') { // when things change a lot
                        $.proxy(this.init(data), this);
                    } else { // quicker, less wasteful
                        this.save_button.attr('disabled', true).removeClass('btn-info').text('Saved')
                                .click($.proxy(this.save, this));
                        this.unsavedChanges = false;
                        this.test_button.removeAttr('disabled');
                    }

                    // not necessary because it's reloaded. should I be more economic about all this DOM and HTTP jazz? there's basically 2 situations where a reload makes things easier: emails where the accounts have been updated, surveys which went from "open" to "chose one". One day...
                }, this))
                .fail($.proxy(function (e, x, settings, exception) {
                    this.save_button.removeAttr('disabled').html(old_text);
                    ajaxErrorHandling(e, x, settings, exception);
                }, this));

        return false;
    };

    // https://gist.github.com/duncansmart/5267653
    // Hook up ACE editor to all textareas with data-editor attribute
    RunUnit.prototype.hookAceToTextarea = function (textarea) {
        var mode = textarea.data('editor');

        var editDiv = $('<div>', {
            position: 'absolute',
            width: textarea.width(),
            height: textarea.height(),
            'class': textarea.attr('class')
        }).insertBefore(textarea);

        textarea.css('display', 'none');

        //	   ace.require("ace/ext/language_tools");

        this.editor = ace.edit(editDiv[0]);
        this.editor.setOptions({
            minLines: textarea.attr('rows') ? textarea.attr('rows') : 3,
            maxLines: 30
        });
        this.editor.setTheme("ace/theme/textmate");
        this.editor.$blockScrolling = Infinity;
        var session = this.editor.getSession();
        session.setValue(textarea.val());
        this.editor.renderer.setShowGutter(false);

        session.setUseWrapMode(true);
        session.setWrapLimitRange(42, 42);
        session.setMode("ace/mode/" + mode);

        this.editor.on('change', $.proxy(this.changes, this));

        return session;
    };

    RunUnit.prototype.removeFromRun = function (e, confirm)
    {
        e.preventDefault();
        $(".tooltip").hide();
        var self = this;
        var event = e;
        var $unit = this.block, $modal;
        var action = this.run.url + "/" + this.remove_button.attr('href');
        var data = {'run_unit_id': this.run_unit_id};
        if (confirm === 'yes') {
            data.confirm = 'yes';
        }
        $unit.hide();
        $.ajax(
            {
                url: action,
                dataType: 'html',
                data: data,
                method: 'POST'
            }).done($.proxy(function (data)  {
                $unit.show();
                if (!data) {
                    return;
                } else if (data === 'warn') {
                    $modal = $($.parseHTML(getHTMLTemplate('tpl-confirmation', {
                        'content': 'Are you sure you want to delete this run unit and all it\'s data?'
                    })));
                    $modal.on('shown.bs.modal', function () {
                        $modal.find('.btn-yes').click(function (e) {
                            self.removeFromRun(event, 'yes');
                            $modal.modal('hide');
                        });
                        $modal.find('.btn-no').click(function (e) {
                            $modal.modal('hide');
                            self.removeFromRun(event, 'no');
                        });
                    }).on('hidden.bs.modal', function () {
                        $modal.remove();
                    }).modal('show');
                } else {
                    $unit.html(data);
                    $unit.show();
                    var whereitat = this.run.units.indexOf(this);
                    if (whereitat > -1) {
                        this.run.units.splice(whereitat, 1); // remove from the run unit list
                    }
                }
            }, this)).fail(function (e, x, settings, exception) {
                $unit.show();
                ajaxErrorHandling(e, x, settings, exception);
            });

        return false;
    };

    RunUnit.prototype.serialize = function ()
    {
        var arr = this.save_inputs.serializeArray();
        var myself = {};

        myself.type = this.block.find('.run_unit_inner').data('type');
        for (var i = 0; i < arr.length; i++)
        {
            if (arr[i].name != "unit_id" && arr[i].name != "run_unit_id" && arr[i].name.substr(0, 8) != "position")
                myself[arr[i].name] = arr[i].value;
            else if (arr[i].name.substr(0, 8) == "position")
                myself.position = arr[i].value;
        }
        return myself;
    };

    function Run(run_form) {
        if (typeof this.autosaved === 'undefined') {
            this.lastSave = $.now(); // only set when loading the first time
            this.autosaved = false;
        }

        this.form = run_form;
        this.form.submit(function () {
            return false;
        });

        this.name = this.form.find('.run_name').val();
        this.url = this.form.prop('action');

        this.units = [];
        var json_units = $.parseJSON(this.form.attr('data-units'));

        for (var i = 0; i < json_units.length; i++) {
            this.units[ i ] = new RunUnit(this);
            this.loadUnit(json_units[i], this.units[ i ]);
        }

        var run = this;
        this.form.find('a.add_run_unit').click(function (e) {
            e.preventDefault();
            var href = $(this).attr('href');
            run.addUnit(href);
            return false;
        });

        this.form.find('a.public-toggle').click(this.publicToggle);

        this.exporter_button = this.form.find('a.export_run_units');
        this.exporter_button.click($.proxy(this.exportUnits, this));

        this.importer_button = this.form.find('a.import_run_units');
        this.importer_button.click($.proxy(this.importUnits, this));

        this.panic_button = $('#btn-panic');
        this.panic_button.click($.proxy(this.panic, this));

        this.reorder_button = this.form.find('a.reorder_units');
        this.reorder_button
                .attr('disabled', 'disabled')
                .click($.proxy(this.reorderUnits, this));

        this.lock_toggle = this.form.find('a.lock-toggle');
        this.lock(this.lock_toggle.hasClass("btn-checked"), this.form);
        this.lock_toggle.click(function (e) {
            e.preventDefault();
            var $this = $(this);
            var on = (!$this.hasClass('btn-checked')) ? 1 : 0;
            $this.toggleClass('btn-checked', on);
            run.lock(on ? true : false, run.form);

            $.ajax({
                url: $this.attr('href'),
                dataType: "html",
                method: 'POST',
                data: {on: on }
            }).fail(ajaxErrorHandling);
            return false;
        });

        window.onbeforeunload = $.proxy(function () {
            var message = false;
            $(this.units).each(function (i, elm)
            {
                if (elm.position_changed || elm.unsavedChanges)
                {
                    message = true;
                    return false;
                }
            });
            if (message) {
                return 'You have unsaved changes.';
            }
        }, this);

    }

    Run.prototype.getMaxPosition = function ()
    {
        var max = null;
        $(this.units).each(function (i, elm) {
            var pos = +elm.position.val();
            if (max === null)
                max = pos;
            else if (pos > max)
                max = pos;
        });
        // if no units are on page then return 0;
        if (max === null) {
            max = 0;
        }
        return max;
    };

    Run.prototype.loadUnit = function (json_data, unit) {
        $.ajax({
            url: this.url + '/ajax_get_unit',
            data: json_data,
            dataType: "html",
            success: $.proxy(function (data, textStatus) {
                unit.init(data);
            }, this)
        });
    };

    Run.prototype.addUnit = function (href)
    {
        var max = this.getMaxPosition();

        var unit = new RunUnit(this);
        this.units.push(unit);

        $.ajax(
                {
                    url: href,
                    dataType: "html",
                    method: 'POST',
                    data:
                            {
                                position: max + 10
                            }
                })
                .done($.proxy(function (data)
                {
                    unit.init(data);
                }, this))
                .fail(ajaxErrorHandling);
    };


    Run.prototype.exportUnits = function () {
        var units = {};
        var runUrl = this.url;
        var unsavedChanges = false;
        var exportDialog = $('<div />');

        var pre_lock = this.lock_toggle.hasClass("btn-checked");
        if (pre_lock) {
            this.lock(false, this.form);
        }

        for (var i = 0; i < this.units.length; i++) {
            var unit = this.units[i].serialize();
            unit.unit_id = this.units[i].unit_id;
            unit.run_unit_id = this.units[i].run_unit_id;
            unsavedChanges = unsavedChanges || this.units[i].unsavedChanges;
            units[unit.position] = unit;
            exportDialog.append($($.parseHTML(getHTMLTemplate('tpl-export-unit-block', {unit_pos: unit.position, unit_json: JSON.stringify(unit, null, "\t")}))));
        }

        if (pre_lock) {
            this.lock(pre_lock, this.form);
        }

        if (unsavedChanges) {
            bootstrap_modal('Please save all changes before export.', 'Unsaved Changes');
            return;
        }

        var export_html = exportDialog.html();//JSON.stringify(units, null, "\t");
        var $modal = $($.parseHTML(getHTMLTemplate('tpl-export-units', {run_name: this.name, 'export_html': export_html})));
        $modal.find('form#export_run_units').attr("action", runUrl + '/export');

        $modal.on('shown.bs.modal', function () {

            $modal.find('.confirm-export').click(function (e) {
                var export_name = $.trim($modal.find('input[name=export_name]').val());
                // If the export name is not valid, no need
                var pattern = /^[a-z0-9_\s]+$/i;
                if (!export_name || !pattern.test(export_name)) {
                    bootstrap_alert("Enter a valid export name", "Export name invalid.", '.run_export_before_alert');
                    return false;
                }
                // Get all selected units. If you can't find any you can't export any
                var selectedUnits = {};
                var $units = $modal.find('.run-export-unit-block');
                $units.each(function () {
                    var $unit = $(this).find('.select');
                    var selected = parseInt($unit.data('selected'), 10),
                            unit_pos = parseInt($unit.data('position'), 10);
                    if (selected && !isNaN(selected) && !isNaN(unit_pos)) {
                        selectedUnits[unit_pos] = units[unit_pos];
                    }
                });
                if ($.isEmptyObject(selectedUnits)) {
                    bootstrap_alert("You need to select at least one unit to export.", "Nothing chosen.", '.run_export_before_alert');
                    return false;
                }
                $modal.find('input[name=units]').val(JSON.stringify(selectedUnits));
                window.setTimeout(function () {
                    $modal.find('.cancel-export').trigger('click');
                }, 100);
                return true;
            });
        }).on('hidden.bs.modal', function () {
            $modal.remove();
        }).modal('show');

        var $codeblocks = $modal.find('pre code');
        $codeblocks.each(function () {
            var codeblock = $(this);
            hljs.highlightBlock(codeblock.get(0));
            codeblock.parents('.run-export-unit-block').find('.select').on('click', function () {
                var $s = $(this);
                var selected = parseInt($s.data('selected'), 10);
                if (selected) {
                    $s.data('selected', 0);
                    $s.find('i').removeClass('fa-check');
                } else {
                    $s.data('selected', 1);
                    $s.find('i').addClass('fa-check');
                }
            });
        });

    };

    Run.prototype.importUnits = function () {
        var module = this;
        var $modal = $('#run-import-modal-dialog');
        if ($modal.length) {
            return $modal.modal('show');
        }

        $.get(this.url + '/ajax_run_import', {'dialog': true}, function (data) {
            $modal = $($.parseHTML(getHTMLTemplate('tpl-import-units', {'content': data}))).attr('id', 'run-import-modal-dialog');
            $modal.find('select').bind('change', function () {
                var val = parseInt($(this).val(), 10);
                if (isNaN(val)) {
                    return;
                }
                var eid = 'selected-run-export-' + val;
                var json_string = getHTMLTemplate(eid);
                $modal.find('textarea').val(JSON.stringify($.parseJSON(json_string), null, "\t"));
            });

            $modal.on('shown.bs.modal', function () {
                $modal.find('.confirm-import').click(function (e) {
                    $(this).hide();
                    return true;
                });
            }).on('hidden.bs.modal', function () {
                $modal.remove();
            }).modal('show');
        });
    };

    Run.prototype.reorderUnits = function (e) {
        e.preventDefault();

        if (typeof this.reorder_button.attr('disabled') === 'undefined')
        {
            var positions = {};
            var are_positions_unique = [];
            var pos;
            var dupes = false;
            $(this.units).each(function (i, elm) {

                pos = +elm.position.val();

                if ($.inArray(pos, are_positions_unique) > -1)
                {
                    bootstrap_alert("You used the position " + pos + " more than once, therefore the new order could not be saved. <a href='#run_unit_" + elm.run_unit_id + "'>Click here to scroll to the duplicated position.</a>", 'Error.', '.run_units');
                    dupes = true;
                    //					return;
                }
                else
                {
                    positions[elm.run_unit_id] = pos;
                    are_positions_unique.push(pos);
                }
            });
            if (!dupes)
            {
                $.ajax(
                        {
                            url: this.reorder_button.attr('href'),
                            dataType: "html",
                            method: 'POST',
                            data: {
                                position: positions
                            }
                        })
                        .done($.proxy(function (data)
                        {
                            $(this.units).each(function (i, elm) {
                                elm.position_changed = false;
                            });
                            this.reorder_button.removeClass('btn-info').attr('disabled', 'disabled');
                            //				var old_positions = $.makeArray($('.run_unit_position input:visible').map(function() { return +$(this).val(); }));
                            var old_order = are_positions_unique.join(','); // for some reason I have to join to compare content order, otherwise annoying behavior with clones etc, slice doesn't help
                            var new_order = are_positions_unique.sort(function (x, y) {
                                return x - y;
                            }).join(',');

                            this.form.find('.pos_changed').removeClass('pos_changed');
                            if (old_order != new_order)
                            {
                                var form = this.form;
                                $(this.units.sort(function (a, b) {
                                    return +a.position.val() - +b.position.val();
                                }))
                                        .each(function (i, elm) {
                                            form.find('.run_units').append(elm.block);
                                        });
                            }
                        }, this))
                        .fail(ajaxErrorHandling);
                return false;
            }
        }
    };
    Run.prototype.lock = function (on, context) {
		var units = this.units;
		for (var i in units) {
			if (units[i].editor) {
				units[i].editor.setReadOnly(on);
			}
		}

        var $elements = context.find('.import_run_units, .run_unit_description, .position, .remove_unit_from_run, .reorder_units, .unit_save, .form-control, select, .from_days, .add_run_unit');
		$elements.each(function(i, elm) {
			if (on) {
				$('#run-unit-choices').hide();
                if (elm.onclick)  {
                    elm.onclick_disabled = elm.onclick;
                    elm.onclick = function (e) {
                        e.preventDefault();
                        return false;
                    };
                }
                $(elm).attr('data-old_disabled', $(elm).attr('disabled'));
                $(elm).attr('disabled', 'disabled');
            } else {
				$('#run-unit-choices').show();
				// if enabled, set back to default
                if (elm.onclick_disabled) {
					// if there was a default
                    elm.onclick = elm.onclick_disabled;
				}
                if ($(elm).attr('data-old-disabled') && $(elm).attr('data-old-disabled') !== '') {
                    $(elm).attr('disabled', $(elm).attr('data-old-disabled'));
				} else {
                    $(elm).removeAttr('disabled');
				}
            }
		});
    };

    Run.prototype.publicToggle = function (e) {
        var $this = $(this);
        $this.parents(".btn-group").find(".btn-checked").removeClass("btn-checked");
        $this.toggleClass('btn-checked', 1);
        $.ajax({
            url: $this.attr('href'),
            dataType: "html",
            method: 'POST',
        }).fail(ajaxErrorHandling);
        return false;
    };

    Run.prototype.panic = function(e) {
        var vars = {
            'content': '<h4>Don\'t panic!</h4>'+
                       '<p>Are things not going your way? Users end up in the wrong places, too many emails being sent?' +
                       '<p>This run will be <b>made private</b>, <b>locked</b> and <b>cron tasks (automatic email and text messages) will be disabled</b>. You will need to manually undo these actions later.</p>' +
                       '<p>This will essentially buy you some time to fix the bug or get help. By configuring the service message under settings, you can show your users an explanation.</p>',
            'yes_url': this.url + '/panic',
            'no_url': 'javascript:void(0);'
        };

        var $modal = $($.parseHTML(getHTMLTemplate('tpl-confirmation', vars))).attr('id', 'run-panic-dialog');
        $modal.on('shown.bs.modal', function () {
            $modal.find('.btn-yes').click(function (e) {
                document.location = $(this).data('yes');
            });
        }).on('hidden.bs.modal', function () {
            $modal.remove();
        }).modal('show');
    };

    $(function () { // on domready
        $('.edit_run').each(function (i, elm) {
            new Run($(elm));
        });
    });
}(jQuery));