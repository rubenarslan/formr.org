import $ from 'jquery';

export class FormMonkey {
    constructor(survey) {
        this.survey = survey;
    }

    doMonkey(monkey_iteration) {
        if (monkey_iteration > 2) {
            window.setTimeout(() => {
                $("form.main_formr_survey button[type=submit]").click();
            }, 700);
            return false;
        }
        else if (monkey_iteration === undefined)
            monkey_iteration = 0;
        else
            monkey_iteration++;

        this.survey.dont_update = true;

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

        items_left.each((i, formRow) => {
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
            for (var j = 0, m = select2Elements.length; j < m; j++) {
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
            for (j = 0, m = inputElements.length; j < m; j++) {
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
        items_left.each((i, elm) => {
            $(elm).trigger('change');
        });
        this.survey.dont_update = false;
        this.survey.update();
        this.doMonkey(monkey_iteration);
    }
} 