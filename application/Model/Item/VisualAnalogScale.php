<?php

/**
 * Visual analog scale (VAS).
 *
 * Like Range, but with no default value: empty until the participant
 * actually moves the slider. The visible <input type=range> renders
 * without a `name`, so its (always-present) midpoint position is never
 * submitted; a sibling <input type=hidden> owns the form name and stays
 * empty until the per-item touch listener copies the slider's value
 * across on first interaction. Required-validation therefore catches
 * "participant never touched it" — server-side, the same path as a
 * blank required text field.
 *
 * CSS: until the wrapper carries `.vas-touched`, the slider thumb is
 * hidden via webroot/assets/common/css/custom_item_classes.css so the
 * participant sees a bare horizontal track and is prompted to choose
 * a position rather than confirming a default.
 *
 * v1 + v2 both render this verbatim — the inline touch listener is
 * vanilla JS so neither bundle needs a registration entry. The same
 * applies to back-navigation in v2: if the server has already stored
 * a value, render_input emits `vas-touched` and pre-positions the
 * slider thumb.
 */
class VisualAnalogScale_Item extends Range_Item {

    public $type = 'visual_analog_scale';
    public $input_attributes = array('type' => 'range', 'min' => 0, 'max' => 100, 'step' => 1);
    public $mysql_field = 'INT UNSIGNED DEFAULT NULL';

    protected function setMoreOptions() {
        parent::setMoreOptions();
        $this->classes_wrapper[] = 'vas-wrapper';
        $this->classes_input[] = 'vas-display';
    }

    protected function render_input() {
        $name = $this->input_attributes['name'];
        $isTouched = ($this->value_validated !== null && $this->value_validated !== '');

        // The visible range is purely cosmetic. It must NOT carry the form
        // `name` — a native range always submits some value, so an unmoved
        // slider would defeat the "no default" contract. It also must not
        // be `required`: the hidden sibling carries that, so client-side
        // native validation focuses the right element.
        $displayAttrs = $this->input_attributes;
        unset($displayAttrs['name']);
        unset($displayAttrs['required']);
        $displayAttrs['id'] = 'item' . $this->id . '_range';
        if ($isTouched) {
            $displayAttrs['value'] = $this->value_validated;
        } else {
            // No `value` attr — let the browser pick the midpoint visually.
            // The thumb is hidden via CSS until the wrapper goes
            // `.vas-touched`, so the visible default is never read as an
            // answer.
            unset($displayAttrs['value']);
        }

        $hiddenAttrs = array(
            'type' => 'hidden',
            'name' => $name,
            'id' => 'item' . $this->id,
        );
        if (!empty($this->input_attributes['required'])) {
            $hiddenAttrs['required'] = 'required';
        }
        if ($isTouched) {
            $hiddenAttrs['value'] = $this->value_validated;
        }

        $touchedClass = $isTouched ? ' vas-touched' : '';

        $tpl = '<span class="vas-controls%{touched_class}" data-vas>'
             . '%{left_label}'
             . '<input %{display_attrs} />'
             . '%{right_label}'
             . '<input %{hidden_attrs} />'
             // Inline touch listener so the item works in both v1 (legacy
             // bundle) and v2 (form bundle) without either having to know
             // about VAS. document.currentScript points to this <script>;
             // its parent is the .vas-controls wrapper. Idempotent — the
             // dataset guard makes a re-mount during a page transition a
             // no-op rather than a double-fire.
             // Imperatively setting `h.value` does NOT trigger native
             // input/change events, so Alpine's $root delegate at
             // form/js/showif/alpine.js never re-syncs reactive state for
             // the VAS field (the bubbled event's target is the visible
             // range, which has no `name` and is filtered out by the
             // _syncInput guard). Dispatch bubbling input+change on the
             // hidden input after the copy so x-showif dependencies on a
             // VAS field actually re-evaluate as the slider moves.
             . '<script>(function(s){if(s.dataset.vasInit)return;s.dataset.vasInit="1";var r=s.querySelector(".vas-display"),h=s.querySelector("input[type=hidden]");function t(){h.value=r.value;s.classList.add("vas-touched");h.dispatchEvent(new Event("input",{bubbles:true}));h.dispatchEvent(new Event("change",{bubbles:true}));}r.addEventListener("input",t);r.addEventListener("change",t);})(document.currentScript.parentNode);</script>'
             . '</span>';

        return Template::replace($tpl, array(
            'touched_class' => $touchedClass,
            'left_label' => $this->renderPadLabel(1, 'right'),
            'display_attrs' => self::_parseAttributes($displayAttrs, array('required')),
            'right_label' => $this->renderPadLabel(2, 'left'),
            'hidden_attrs' => self::_parseAttributes($hiddenAttrs, array('required')),
        ));
    }

    private function renderPadLabel($choice, $pad) {
        if (isset($this->choices[$choice])) {
            return sprintf('<label class="pad-%s keep-label">%s</label>', $pad, $this->choices[$choice]);
        }
        return '';
    }

}
