import $ from 'jquery';
import webshim from 'webshim';

export class ButtonGroup {
    constructor(item) {
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

    button_click(group, $btn, $input) {
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
    }
}

export function initializeButtonGroups() {
    webshim.ready('DOM forms forms-ext dom-extend', function () {
        var mc_buttons = $('div.btn-radio, div.btn-checkbox, div.btn-check');
        mc_buttons.each(function (i, elm) {
            new ButtonGroup(elm);
        });
    });
} 