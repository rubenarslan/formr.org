import $ from 'jquery';
import webshim from 'webshim';
import 'select2/select2.js';
import 'select2/select2.css';

export function initializeSelect2Components() {
    webshim.ready('DOM forms forms-ext dom-extend', function () {
        // Initialize basic select2 elements
        $("select.select2zone, .form-group.select2 select").each(function (i, elm) {
            var slct = $(elm);
            slct.select2();
            webshim.ready("dom-extend", function () {
                webshim.addShadowDom(slct, slct.select2("container"));
            });
        });

        // Initialize select2 pills
        $(".select2pills select").each(function (i, elm) {
            var slct = $(elm);
            slct.select2({
                width: "width:300px",
                dropdownCssClass: "bigdrop",
                maximumSelectionSize: slct.data('select2maximumSelectionSize'),
                maximumInputLength: slct.data('select2maximumInputLength'),
                formatResult: function (pill) {
                    if (pill.id !== '') {
                        var markup = "<strong>" + pill.text + "</strong><br><img width='200px' alt='" + pill.text + "' src='assets/img/pills/" + pill.id + ".jpg'/>";
                        return markup;
                    } else
                        return '';
                },
                formatSelection: function (pill) {
                    return pill.text;
                },
                escapeMarkup: function (m) {
                    return m;
                }
            }).on("change select2-open", function (e) {
                document.activeElement.blur();
            });
            webshim.ready("dom-extend", function () {
                webshim.addShadowDom(slct, slct.select2("container"));
            });
        });

        // Initialize people list
        $(".people_list textarea").each(function (i, elm) {
            var slct = $(elm);
            slct.select2({
                width: "element",
                height: "2000px",
                data: [],
                formatNoMatches: function (term) {
                    if (term !== '')
                        return "Füge '" + term + "' hinzu!";
                    else
                        return "Weitere Personen hinzufügen.";
                },
                tokenSeparators: ["\n"],
                separator: '\n',
                createSearchChoice: function (term, data) {
                    if ($(data).filter(function () {
                        return this.text.localeCompare(term) === 0;
                    }).length === 0) {
                        term = term.replace("\n", '; ');
                        return {id: term, text: term};
                    }
                },
                initSelection: function (element, callback) {
                    var elements = element.val().split("\n");
                    var data = [];
                    for (var i = 0; i < elements.length; i++) {
                        data.push({id: elements[i], text: elements[i]});
                    }
                    callback(data);
                },
                maximumSelectionSize: 15,
                maximumInputLength: 50,
                formatResultCssClass: function (obj) {
                    return "people_list_results";
                },
                multiple: true,
                allowClear: true,
                escapeMarkup: function (m) {
                    return m;
                }
            }).removeClass("form-control");
            var plus = $("<span class='select2-plus'>+</span>");
            plus.insertBefore(slct.select2("container").find('.select2-search-field input'));
            webshim.ready("dom-extend", function () {
                webshim.addShadowDom(slct, slct.select2("container"));
            });
        });

        // Initialize select2add
        $("input.select2add").each(function (i, elm) {
            var slct = $(elm);
            if (slct.select2("container").hasClass("select2-container")) // is already select2
                return;
            var slctdata0 = slct.attr('data-select2add');
            if (typeof slctdata0 != 'object') {
                slctdata0 = $.parseJSON(slctdata0);
            }
            var slctdata_arr;
            var slctdata = [];
            for (var u = 0; u < slctdata0.length; u++) {
                slctdata_arr = slctdata0[u].id.split(",");
                for (var j = 0; j < slctdata_arr.length; j++) {
                    if (slctdata_arr[j].trim().length > 0) {
                        slctdata.push({"id": slctdata_arr[j], "text": slctdata_arr[j]});
                    }
                }
            }

            var is_network_selector = $(elm).parents(".form-group").hasClass("network_select") || 
                                    $(elm).parents(".form-group").hasClass("ratgeber_class") || 
                                    $(elm).parents(".form-group").hasClass("cant_add_choice");

            slct.select2({
                createSearchChoice: function (term, data) {
                    if (is_network_selector)
                        return null; // don't allow choice creation

                    if ($(data).filter(function () {
                        return this.text.localeCompare(term) === 0;
                    }).length === 0) {
                        term = term.replace(',', ';');
                        return {id: term, text: term};
                    }
                },
                initSelection: function (element, callback) {
                    var data;
                    if (!!slct.data('select2multiple')) {
                        var intermed = element.val().split(",");
                        data = new Array(intermed.length);
                        for (var e = 0; e < intermed.length; e++) {
                            data[e] = {id: intermed[e], text: intermed[e]};
                        }
                    } else {
                        data = {id: element.val(), text: element.val()};
                    }
                    $.each(slctdata, function (k, v) {
                        if (v.id === element.val()) {
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
                escapeMarkup: function (m) {
                    return m;
                }
            });
            webshim.ready('forms forms-ext dom-extend form-validators', function () {
                webshim.addShadowDom(slct, slct.select2("container"));
            });
        });
    });
} 