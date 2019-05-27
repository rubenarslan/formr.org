<?php

class CheckButton_Item extends Check_Item {

    public $mysql_field = 'TINYINT UNSIGNED DEFAULT NULL';
    protected $js_hidden = true;

    protected function setMoreOptions() {
        parent::setMoreOptions();
        $this->classes_wrapper[] = 'btn-check';
    }

    protected function render_label() {
        $template = '<label class="%{class}">%{error} %{text} </label>';
        return Template::replace($template, array(
                    'class' => implode(' ', $this->classes_label),
                    'error' => $this->render_error_tip(),
                    'text' => $this->label_parsed,
        ));
    }

    protected function render_appended() {
        $template = '
			<div class="btn-group js_shown">
				<button type="button" class="btn" data-for="item%s_1"><i class="fa fa-2x fa-fw"></i></button>
			</div>';
        return sprintf($template, $this->id);
    }

}
