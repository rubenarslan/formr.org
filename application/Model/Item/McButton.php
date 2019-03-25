<?php

class McButton_Item extends Mc_Item {

    public $mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
    protected $js_hidden = true;

    protected function setMoreOptions() {
        parent::setMoreOptions();
        $this->classes_wrapper[] = 'btn-radio';
    }

    protected function render_appended() {
        $ret = '<div class="btn-group js_shown">';
        foreach ($this->choices as $value => $option) {
            $tpl = '
				<button type="button" class="btn" data-for="item%{id}_%{value}">
					<span class="btn_value">%{value}</span><span class="btn_label">%{option}</span>
				</button>
			';
            $ret .= Template::replace($tpl, array('id' => $this->id, 'value' => $value, 'option' => $option));
        }
        $ret .= '</div>';

        return $ret;
    }

}
